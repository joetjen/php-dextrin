<?php

declare(strict_types=1);

namespace JOetjen\Dextrin;

/**
 * One of DXN's three forms: text (`.dxn`), binary (`.dxnb`) and schemas
 * (`.dxns`). See {@see Format}.
 */
enum Form: string
{
    case Text = 'text';
    case Binary = 'binary';
    case Schema = 'schema';
}
