<?php
// PHPStan bootstrap — define constants and stubs used across api/ and hrms/ code
// so static analysis doesn't fail on undefined functions/constants.

// Common constants referenced in API code
defined('ESS_GUARD_ROLES_MANAGER') || define('ESS_GUARD_ROLES_MANAGER', ['supervisor','manager','hr','admin']);
defined('ESS_GUARD_ROLES_ADMIN') || define('ESS_GUARD_ROLES_ADMIN', ['hr','admin']);
