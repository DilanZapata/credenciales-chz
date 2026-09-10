<?php
declare(strict_types=1);

namespace App\Services;

use app\models\totpModel;

/** Envoltorio de transicion sobre `app\models\totpModel`. */
final class TotpService
{
    public function generateSecret(int $bytes = 20): string                       { return totpModel::generateSecret($bytes); }
    public function verify(string $s, string $c, int $v = 1): bool                { return totpModel::verify($s, $c, $v); }
    public function codeAt(string $s, int $c): string                             { return totpModel::codeAt($s, $c); }
    public function generateBackupCodes(int $n = 10): array                       { return totpModel::generateBackupCodes($n); }

    public function provisioningUri(string $s, string $cuenta, string $emisor): string
    {
        return totpModel::provisioningUri($s, $cuenta, $emisor);
    }
}
