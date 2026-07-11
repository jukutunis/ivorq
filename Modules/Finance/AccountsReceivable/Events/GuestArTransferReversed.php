<?php
namespace Modules\Finance\AccountsReceivable\Events;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Finance\AccountsReceivable\Models\GuestArTransferDecision;
class GuestArTransferReversed { use Dispatchable, SerializesModels; public function __construct(public GuestArTransferDecision $decision) {} }
