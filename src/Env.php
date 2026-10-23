<?php declare( strict_types = 1 );
/**
 * @package jcvignoli\graphql
 */
namespace jcvignoli\Graphql;

use Dotenv\Dotenv;

/**
 * Class Env
 *
 * Handles loading of environment variables from .env files with flexible path searching.
 */
class Env {

	/**
	 * Loads environment variables from .env files.
	 *
	 * Searches in the starting directory and up to 5 levels of parent directories.
	 * If a 'vendor' directory is encountered, the directory directly above it
	 * is given precedence in the search.
	 *
	 * @param string $startDirectory The directory to start searching from
	 * @param null|string $envName Optional: filename, .env by default
	 *
	 * @return void
	 * @throws \Dotenv\Exception\InvalidPathException If no .env file is found in any searched path.
	 * @see \Dotenv\Dotenv
	 */
	public static function load( string $startDirectory, ?string $envName = '.env' ): void {
		$searchPaths      = array( $startDirectory );
		$currentDirectory = $startDirectory;

		for ( $iteration = 0; $iteration < 6; $iteration++ ) {
			$parentDirectory = dirname( $currentDirectory );
			if ( $parentDirectory === $currentDirectory ) {
				break;
			}

			// If we are currently in a directory named 'vendor', the parent directory is the project root.
			$current = basename( $currentDirectory );
			if ( str_contains( $current, 'vendor' ) === true ) {
				array_unshift( $searchPaths, $parentDirectory );
			}

			$searchPaths[]    = $parentDirectory;
			$currentDirectory = $parentDirectory;
		}

		$dotenv = Dotenv::createImmutable( array_values( array_unique( $searchPaths ) ), $envName );
		$dotenv->load();
	}
}
