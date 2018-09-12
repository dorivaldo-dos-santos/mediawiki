<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 */

require_once __DIR__ . '/../Maintenance.php';

/**
 * Manage foreign resources registered with ResourceLoader.
 *
 * @ingroup Maintenance
 * @since 1.32
 */
class ManageForeignResources extends Maintenance {
	private $defaultAlgo = 'sha384';
	private $tmpParentDir;
	private $action;
	private $failAfterOutput = false;

	public function __construct() {
		global $IP;
		parent::__construct();
		$this->addDescription( <<<TEXT
Manage foreign resources registered with ResourceLoader.

This helps developers to download, verify and update local copies of upstream
libraries registered as ResourceLoader modules. See also foreign-resources.yaml.

For sources that don't publish an integrity hash, omit "integrity" (or leave empty)
and run the "make-sri" action to compute the missing hashes.

This script runs in dry-run mode by default. Use --update to actually change,
remove, or add files to resources/lib/.
TEXT
		);
		$this->addArg( 'action', 'One of "update", "verify" or "make-sri"', true );
		$this->addArg( 'module', 'Name of a single module (Default: all)', false );
		$this->addOption( 'verbose', 'Be verbose', false, false, 'v' );

		// Use a directory in $IP instead of wfTempDir() because
		// PHP's rename() does not work across file systems.
		$this->tmpParentDir = "{$IP}/resources/tmp";
	}

	public function execute() {
		global $IP;
		$this->action = $this->getArg( 0 );
		if ( !in_array( $this->action, [ 'update', 'verify', 'make-sri' ] ) ) {
			$this->fatalError( "Invalid action argument." );
		}

		$registry = $this->parseBasicYaml(
			file_get_contents( __DIR__ . '/foreign-resources.yaml' )
		);
		$module = $this->getArg( 1, 'all' );
		foreach ( $registry as $moduleName => $info ) {
			if ( $module !== 'all' && $moduleName !== $module ) {
				continue;
			}
			$this->verbose( "\n### {$moduleName}\n\n" );
			$destDir = "{$IP}/resources/lib/$moduleName";

			if ( $this->action === 'update' ) {
				$this->output( "... updating '{$moduleName}'\n" );
				$this->verbose( "... emptying /resources/lib/$moduleName\n" );
				wfRecursiveRemoveDir( $destDir );
			} elseif ( $this->action === 'verify' ) {
				$this->output( "... verifying '{$moduleName}'\n" );
			} else {
				$this->output( "... checking '{$moduleName}'\n" );
			}

			$this->verbose( "... preparing {$this->tmpParentDir}\n" );
			wfRecursiveRemoveDir( $this->tmpParentDir );
			if ( !wfMkdirParents( $this->tmpParentDir ) ) {
				$this->fatalError( "Unable to create {$this->tmpParentDir}" );
			}

			if ( !isset( $info['type'] ) ) {
				$this->fatalError( "Module '$moduleName' must have a 'type' key." );
			}
			switch ( $info['type'] ) {
				case 'tar':
					$this->handleTypeTar( $moduleName, $destDir, $info );
					break;
				case 'file':
					$this->handleTypeFile( $moduleName, $destDir, $info );
					break;
				case 'multi-file':
					$this->handleTypeMultiFile( $moduleName, $destDir, $info );
					break;
				default:
					$this->fatalError( "Unknown type '{$info['type']}' for '$moduleName'" );
			}
		}

		$this->cleanUp();
		$this->output( "\nDone!\n" );
		if ( $this->failAfterOutput ) {
			// The verify mode should check all modules/files and fail after, not during.
			return false;
		}
	}

	private function fetch( $src, $integrity ) {
		$data = Http::get( $src, [ 'followRedirects' => false ] );
		if ( $data === false ) {
			$this->fatalError( "Failed to download resource at {$src}" );
		}
		$algo = $integrity === null ? $this->defaultAlgo : explode( '-', $integrity )[0];
		$actualIntegrity = $algo . '-' . base64_encode( hash( $algo, $data, true ) );
		if ( $integrity === $actualIntegrity ) {
			$this->verbose( "... passed integrity check for {$src}\n" );
		} else {
			if ( $this->action === 'make-sri' ) {
				$this->output( "Integrity for {$src}\n\tintegrity: ${actualIntegrity}\n" );
			} else {
				$this->fatalError( "Integrity check failed for {$src}\n" .
					"\tExpected: {$integrity}\n" .
					"\tActual: {$actualIntegrity}"
				);
			}
		}
		return $data;
	}

	private function handleTypeFile( $moduleName, $destDir, array $info ) {
		if ( !isset( $info['src'] ) ) {
			$this->fatalError( "Module '$moduleName' must have a 'src' key." );
		}
		$data = $this->fetch( $info['src'], $info['integrity'] ?? null );
		$dest = $info['dest'] ?? basename( $info['src'] );
		$path = "$destDir/$dest";
		if ( $this->action === 'verify' && sha1_file( $path ) !== sha1( $data ) ) {
			$this->fatalError( "File for '$moduleName' is different." );
		} elseif ( $this->action === 'update' ) {
			wfMkdirParents( $destDir );
			file_put_contents( "$destDir/$dest", $data );
		}
	}

	private function handleTypeMultiFile( $moduleName, $destDir, array $info ) {
		if ( !isset( $info['files'] ) ) {
			$this->fatalError( "Module '$moduleName' must have a 'files' key." );
		}
		foreach ( $info['files'] as $dest => $file ) {
			if ( !isset( $file['src'] ) ) {
				$this->fatalError( "Module '$moduleName' file '$dest' must have a 'src' key." );
			}
			$data = $this->fetch( $file['src'], $file['integrity'] ?? null );
			$path = "$destDir/$dest";
			if ( $this->action === 'verify' && sha1_file( $path ) !== sha1( $data ) ) {
				$this->fatalError( "File '$dest' for '$moduleName' is different." );
			} elseif ( $this->action === 'update' ) {
				wfMkdirParents( $destDir );
				file_put_contents( "$destDir/$dest", $data );
			}
		}
	}

	private function handleTypeTar( $moduleName, $destDir, array $info ) {
		$info += [ 'src' => null, 'integrity' => null, 'dest' => null ];
		if ( $info['src'] === null ) {
			$this->fatalError( "Module '$moduleName' must have a 'src' key." );
		}
		// Download the resource to a temporary file and open it
		$data = $this->fetch( $info['src'], $info['integrity' ] );
		$tmpFile = "{$this->tmpParentDir}/$moduleName.tar";
		$this->verbose( "... writing '$moduleName' src to $tmpFile\n" );
		file_put_contents( $tmpFile, $data );
		$p = new PharData( $tmpFile );
		$tmpDir = "{$this->tmpParentDir}/$moduleName";
		$p->extractTo( $tmpDir );
		unset( $data, $p );

		if ( $info['dest'] === null ) {
			// Default: Replace the entire directory
			$toCopy = [ $tmpDir => $destDir ];
		} else {
			// Expand and normalise the 'dest' entries
			$toCopy = [];
			foreach ( $info['dest'] as $fromSubPath => $toSubPath ) {
				// Use glob() to expand wildcards and check existence
				$fromPaths = glob( "{$tmpDir}/{$fromSubPath}", GLOB_BRACE );
				if ( !$fromPaths ) {
					$this->fatalError( "Path '$fromSubPath' of '$moduleName' not found." );
				}
				foreach ( $fromPaths as $fromPath ) {
					$toCopy[$fromPath] = $toSubPath === null
						? "$destDir/" . basename( $fromPath )
						: "$destDir/$toSubPath/" . basename( $fromPath );
				}
			}
		}
		foreach ( $toCopy as $from => $to ) {
			if ( $this->action === 'verify' ) {
				$this->verbose( "... verifying $to\n" );
				if ( is_dir( $from ) ) {
					$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator(
						$from,
						RecursiveDirectoryIterator::SKIP_DOTS
					) );
					foreach ( $rii as $file ) {
						$remote = $file->getPathname();
						$local = strtr( $remote, [ $from => $to ] );
						if ( sha1_file( $remote ) !== sha1_file( $local ) ) {
							$this->error( "File '$local' is different." );
							$this->failAfterOutput = true;
						}
					}
				} elseif ( sha1_file( $from ) !== sha1_file( $to ) ) {
					$this->error( "File '$to' is different." );
					$this->failAfterOutput = true;
				}
			} elseif ( $this->action === 'update' ) {
				$this->verbose( "... moving $from to $to\n" );
				wfMkdirParents( dirname( $to ) );
				if ( !rename( $from, $to ) ) {
					$this->fatalError( "Could not move $from to $to." );
				}
			}
		}
	}

	private function verbose( $text ) {
		if ( $this->hasOption( 'verbose' ) ) {
			$this->output( $text );
		}
	}

	private function cleanUp() {
		wfRecursiveRemoveDir( $this->tmpParentDir );
	}

	protected function fatalError( $msg, $exitCode = 1 ) {
		$this->cleanUp();
		parent::fatalError( $msg, $exitCode );
	}

	/**
	 * Basic YAML parser.
	 *
	 * Supports only string or object values, and 2 spaces indentation.
	 *
	 * @todo Just ship symfony/yaml.
	 * @param string $input
	 * @return array
	 */
	private function parseBasicYaml( $input ) {
		$lines = explode( "\n", $input );
		$root = [];
		$stack = [ &$root ];
		$prev = 0;
		foreach ( $lines as $i => $text ) {
			$line = $i + 1;
			$trimmed = ltrim( $text, ' ' );
			if ( $trimmed === '' || $trimmed[0] === '#' ) {
				continue;
			}
			$indent = strlen( $text ) - strlen( $trimmed );
			if ( $indent % 2 !== 0 ) {
				throw new Exception( __METHOD__ . ": Odd indentation on line $line." );
			}
			$depth = $indent === 0 ? 0 : ( $indent / 2 );
			if ( $depth < $prev ) {
				// Close previous branches we can't re-enter
				array_splice( $stack, $depth + 1 );
			}
			if ( !array_key_exists( $depth, $stack ) ) {
				throw new Exception( __METHOD__ . ": Too much indentation on line $line." );
			}
			if ( strpos( $trimmed, ':' ) === false ) {
				throw new Exception( __METHOD__ . ": Missing colon on line $line." );
			}
			$dest =& $stack[ $depth ];
			if ( $dest === null ) {
				// Promote from null to object
				$dest = [];
			}
			list( $key, $val ) = explode( ':', $trimmed, 2 );
			$val = ltrim( $val, ' ' );
			if ( $val !== '' ) {
				// Add string
				$dest[ $key ] = $val;
			} else {
				// Add null (may become an object later)
				$val = null;
				$stack[] = &$val;
				$dest[ $key ] = &$val;
			}
			$prev = $depth;
			unset( $dest, $val );
		}
		return $root;
	}
}

$maintClass = ManageForeignResources::class;
require_once RUN_MAINTENANCE_IF_MAIN;
