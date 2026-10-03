<?php

namespace App\Services\Shipping;

use RuntimeException;

/** Shipping rates could not be fetched; the message is safe to show (Bahasa Indonesia). */
final class ShippingException extends RuntimeException
{
}
