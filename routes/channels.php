<?php

use App\Broadcasting\PokerHandChannel;
use App\Broadcasting\RoomChannel;
use App\Broadcasting\WalletChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('wallets.{walletId}', WalletChannel::class);
Broadcast::channel('rooms.{roomId}', RoomChannel::class);
Broadcast::channel('poker-hands.{handId}', PokerHandChannel::class);
