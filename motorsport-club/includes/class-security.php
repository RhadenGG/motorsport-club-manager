<?php
if ( ! defined( 'ABSPATH' ) ) exit;
// Registration is disabled via Settings → General → uncheck "Anyone can register"
// No additional security logic needed.
class MSC_Security {
    public static function init() {}
}
