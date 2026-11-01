<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

/** Identifies events owned by NickServ without coupling publishers to concrete payloads. */
interface NickServEvent {}
