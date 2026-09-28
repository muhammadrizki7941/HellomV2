<?php

namespace App\Events\Pos;

/** The order was cancelled (dibatalkan) before payment: stock goes back, redeemed points are returned. */
final class OrderVoided extends OrderEvent
{
}
