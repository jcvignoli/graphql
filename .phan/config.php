<?php declare( strict_types = 1 );
/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 */
return array(

	// Supported values: `'5.6'`, `'7.0'`, `'7.1'`, `'7.2'`, `'7.3'`, `'7.4'`,
	// `'8.0'`, `'8.1'`, `null`.
	// If this is set to `null`,
	// then Phan assumes the PHP version which is closest to the minor version
	// of the php executable used to execute Phan.
	'target_php_version' => null,

	// Issue::SEVERITY_LOW(0), Issue::SEVERITY_NORMAL(5), Issue::SEVERITY_CRITICAL(10)
	// Low is the highest level
	'minimum_severity' => 0,

	// Backwards Compatibility Checking. This is slow
	// and expensive, but you should consider running
	// it before upgrading your version of PHP to a
	// new version that has backward compatibility
	// breaks. (Also see target_php_version)
	'backward_compatibility_checks' => false,

	// If enabled, check all methods that override a
	// parent method to make sure its signature is
	// compatible with the parent's. This check
	// can add quite a bit of time to the analysis.
	'analyze_signature_compatibility' => true,

	// If true, missing properties will be created when
	// they are first seen. If false, we'll report an
	// error message if there is an attempt to write
	// to a class property that wasn't explicitly
	// defined.
	'allow_missing_properties' => false,

	// If enabled, scalars (int, float, bool, true, false, string, null)
	// are treated as if they can cast to each other.
	'scalar_implicit_cast' => false,

	// Set to true in order to attempt to detect dead (unreferenced) code.
	// Keep in mind that the results will only be a guess given that classes,
	// properties, constants and methods can be referenced as variables
	// (like $class->$property or $class->$method()) in ways that we're unable to make sense of.
	'dead_code_detection' => false,

	// If true, seemingly undeclared variables in the global
	// scope will be ignored. This is useful for projects
	// with complicated cross-file globals that you have no
	// hope of fixing.
	'ignore_undeclared_variables_in_global_scope' => false,

	// A list of directories that should be parsed for class and
	// method information. After excluding the directories
	// defined in exclude_analysis_directory_list, the remaining
	// files will be statically analyzed for errors.
	//
	// Thus, both first-party and third-party code being used by
	// your application should be included in this list.
	'directory_list'            => array(
		'web/',
		'vendor/',
	),

	// A directory list that defines files that will be excluded
	// from static analysis, but whose class and method
	// information should be included.
	//
	// Generally, you'll want to include the directories for
	// third-party code (such as "vendor/") in this list.
	//
	// n.b.: If you'd like to parse but not analyze 3rd
	//       party code, directories containing that code
	//       should be added to the `directory_list` as
	//       to `exclude_analysis_directory_list`.
	'exclude_analysis_directory_list' => array(
		'vendor/',
		'web/vendor/',
	),

	// Not developped files
	'exclude_file_list' => array(),

	/**
	 * Add any issue types (such as 'PhanUndeclaredMethod') to this list to inhibit them from being reported.
	 */
	'suppress_issue_types' => array(),

	// A list of plugin files to execute.
	// Plugins which are bundled with Phan can be added here by providing their name
	// (e.g. 'AlwaysReturnPlugin')
	//
	// Documentation about available bundled plugins can be found
	// at https://github.com/phan/phan/tree/v5/.phan/plugins
	//
	// Alternately, you can pass in the full path to a PHP file
	// with the plugin's implementation.
	// (e.g. 'vendor/phan/phan/.phan/plugins/AlwaysReturnPlugin.php')
	'plugins'                         => array(
		'AlwaysReturnPlugin',
		//'DollarDollarPlugin',
		'DuplicateArrayKeyPlugin',
		'DuplicateExpressionPlugin',
		'PregRegexCheckerPlugin',
		// 'PrintfCheckerPlugin', // makes sprintf( ngettext() ) unusable
		'SleepCheckerPlugin',
		'UnreachableCodePlugin',
		'UseReturnValuePlugin',
		'EmptyStatementListPlugin',
		'LoopVariableReusePlugin',
	),
);
