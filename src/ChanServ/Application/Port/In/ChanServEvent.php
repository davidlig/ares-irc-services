<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

/** Identifies events owned by ChanServ without coupling publishers to concrete payloads. */
interface ChanServEvent {}
