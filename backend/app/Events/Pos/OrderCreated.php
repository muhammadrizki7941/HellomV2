<?php

namespace App\Events\Pos;

/** A new order was placed (cashier or self-order). Stock is reserved by a listener inside the order transaction. */
final class OrderCreated extends OrderEvent
{
}
