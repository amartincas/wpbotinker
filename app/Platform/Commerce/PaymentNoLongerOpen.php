<?php

namespace App\Platform\Commerce;

use RuntimeException;

/** The payment was closed (reviewed or expired) while a receipt was being recorded. */
class PaymentNoLongerOpen extends RuntimeException {}
