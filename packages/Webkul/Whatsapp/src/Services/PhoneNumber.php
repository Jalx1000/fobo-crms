<?php

namespace Webkul\Whatsapp\Services;

use Webkul\Core\Services\PhoneNumber as CorePhoneNumber;

/**
 * The rules now live in Core so contact lookup outside WhatsApp (lead creation
 * over the API, for one) normalizes numbers identically. Kept as an alias so
 * the module's own call sites read the same as before.
 */
class PhoneNumber extends CorePhoneNumber {}
