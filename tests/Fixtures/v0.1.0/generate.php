<?php

declare(strict_types=1);

// Writes the `.dxnb` fixtures beside it with php-dextrin 0.1.0, for the
// releases after it to read back (`tests/Binary/V010Test.php`). Run it
// against a checkout of `v0.1.0`, never against the current code:
//
//     git worktree add ../php-dextrin-010 v0.1.0
//     (cd ../php-dextrin-010 && composer dump-autoload)
//     php tests/Fixtures/v0.1.0/generate.php ../php-dextrin-010 OUT_DIR
//
// The value document and the schema are the Elixir fixtures' own
// (`dextrin`'s `test/fixtures/v0.1.3`), so the two can be compared.

[, $checkout, $out] = $argv;
require $checkout . '/vendor/autoload.php';

use JOetjen\Dextrin\Dextrin;
use JOetjen\Dextrin\Registry;
use JOetjen\Dextrin\Schema;
use JOetjen\Dextrin\Value\DXNStruct;

@mkdir($out, 0777, true);

$values = (string) file_get_contents(__DIR__ . '/values.dxn');
file_put_contents($out . '/values.dxnb', Dextrin::encodeBinary(Dextrin::decode($values)));

$registry = Schema::compile(Dextrin::decode((string) file_get_contents(__DIR__ . '/user.dxns')), new Registry());
// 0.1.0 decodes a schema struct to a plain array, so a struct is built
// directly, the way an application hands one to the encoder.
$user = DXNStruct::positional('User', ['Ann', 'ann@example.com', Dextrin::decode(':admin')]);
$bytes = Dextrin::encodeBinary($user, $registry);
file_put_contents($out . '/user_full.dxnb', $bytes);
echo 'user_full: ', bin2hex($bytes), "\n";
