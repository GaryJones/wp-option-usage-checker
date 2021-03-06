=== Option Usage Checker ===
Contributors:      X-team, westonruter
Tags:              options, transients, memcached, object-cache
Requires at least: 3.9
Tested up to:      trunk
Stable tag:        trunk
License:           GPLv2 or later
License URI:       http://www.gnu.org/licenses/gpl-2.0.html

Check for perilous usages of add_option()/update_option(). Dev plugin, not recommended for production. Specifically useful with Memcached Object Cache

== Description ==

The WordPress Options API is used extensively as a key/value store. There are some pitfalls about how to use the API that are easy to fall into, especially when using the popular [Memcached Object Cache plugin](https://wordpress.org/plugins/memcached/).

Memcached has a limit on the bucket size: by default it doesn't let you cache values large than 1MB. On WordPress.com, it is part of the WordPress VIP code requirements to not [cache large values in options](http://vip.wordpress.com/documentation/code-review-what-we-look-for/#caching-large-values-in-options). This plugin will warn if attempting to store a value in an option that is larger than 1MB.

Options in WordPress can be optionally autoloaded when WordPress first boots up. If there are many options that WordPress uses all the time, then there is a speed boost of those options can be fetched from the database all at once up front instead of doing a separate DB query each time one of the option is needed. The `add_option()` method is specifically how to register an option as being autoloaded, or rather how it should be *not* autoloaded:

```php
function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
```

The `update_option()` has an unfortunate default behavior when attempting to update an option that doesn't exist yet: it will call `add_option()` but it will only pass the first two arguments, leaving the default `$autoload` enabled. If you have added many options via `update_option()` but haven't explicitly done `add_option( ... 'no' )` first, then all of those options will be autoloading. It is easy to autoload options by accident. This specifically is a big problem with Memcached Object Cache because the autoloaded options are all cached together as one big serialized array, instead of being cached individually. So it is very easy for the `alloptions` cache to grow larger than 1MB. Once this happens, all of the autoloaded options will no longer be cached and *your high-traffic website will crash*. It is therefore very important that you use autoloaded options very sparingly.

This plugin helps guard against accidental autoloaded options by warning whenever `update_option()` is called for an option that doesn't already exist—one which hasn't been registered with `add_option()`. The best practice enforced here is to always call `add_option()` before calling `update_option()`, and thus to help explicitly indicate whether you want the option to be autoloaded or *not*.

= Error Handling =

The plugin can either report errors as PHP warnings or by throwing exceptions. If you have `WP_DEBUG` enabled, exceptions will be thrown by default. You can override this default behavior via the `option_usage_checker_throw_exceptions` filter:

```php
add_filter( 'option_usage_checker_throw_exceptions', '__return_true' ); // always throw exceptions
```

= Changing Bucket Size Limit =

By default, the cache bucket size limit used is 1MB, which is the default limit for Memcached. You can override this default with a constant:

```php
define( 'OPTION_USAGE_CHECKER_OBJECT_CACHE_BUCKET_MAX_SIZE', 2 * pow( 2, 20 ) ); // increase to 2MB
```

Or via a filter:

```php
add_filter( 'option_value_max_size', function () {
	return 4 * pow( 2, 20 ); // 4MB
} );
```

= Whitelisting update_option() calls =

In Core there are instances of `update_option()` being called on non-existent options. To suppress warnings on such usages, when `update_option()` is called and would raise a warning, the plugin checks the callstack to see if it was called from Core (`wp-includes` or `wp-admin`), and if so the warning will be suppressed by default. You can override this behavior (or whitelist other plugins) with a filter:

```php
add_filter( 'option_usage_checker_whitelisted', function ( $whitelisted, $context ) {
	if ( preg_match( '/my-awesome-plugin/', $context['callee']['file'] ) ) {
		$whitelisted = true;
	}
	return $whitelisted;
}, 10, 2 );
```

If you only want to flag options that contain serialized data ([via @toscho](https://twitter.com/toscho/status/492456173019607042)):

```php
add_filter( 'option_usage_checker_whitelisted', function ( $whitelisted, $context ) {
	if ( ! $whitelisted && is_scalar( $context['value'] ) ) {
		$whitelisted = true;
	}
	return $whitelisted;
}, 10, 2 );
```
