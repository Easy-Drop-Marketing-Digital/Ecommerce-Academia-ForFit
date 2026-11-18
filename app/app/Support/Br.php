<?php

namespace App\Support;

/** Validações e formatações brasileiras (CPF, CEP, telefone). */
class Br
{
    public static function digits(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }

    public static function validCpf(?string $cpf): bool
    {
        $c = self::digits($cpf);
        if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $c[$i] * (($t + 1) - $i);
            }
            $d = ((10 * $sum) % 11) % 10;
            if ((int) $c[$t] !== $d) {
                return false;
            }
        }

        return true;
    }

    /** Celular/fixo com DDD: 10 ou 11 dígitos, DDD válido. */
    public static function validPhone(?string $phone): bool
    {
        $p = self::digits($phone);
        if (str_starts_with($p, '55') && strlen($p) > 11) {
            $p = substr($p, 2);
        }

        return (bool) preg_match('/^[1-9][1-9]\d{8,9}$/', $p);
    }

    public static function luhn(?string $number): bool
    {
        $n = self::digits($number);
        if (strlen($n) < 13 || strlen($n) > 19) {
            return false;
        }
        $sum = 0;
        $alt = false;
        for ($i = strlen($n) - 1; $i >= 0; $i--) {
            $d = (int) $n[$i];
            if ($alt) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }

    public static function formatCpf(string $cpf): string
    {
        $c = self::digits($cpf);

        return strlen($c) === 11 ? substr($c, 0, 3).'.'.substr($c, 3, 3).'.'.substr($c, 6, 3).'-'.substr($c, 9) : $cpf;
    }

    public static function formatCep(string $cep): string
    {
        $c = self::digits($cep);

        return strlen($c) === 8 ? substr($c, 0, 5).'-'.substr($c, 5) : $cep;
    }

    public static function formatPhone(string $phone): string
    {
        $p = self::digits($phone);

        return match (strlen($p)) {
            11 => '('.substr($p, 0, 2).') '.substr($p, 2, 5).'-'.substr($p, 7),
            10 => '('.substr($p, 0, 2).') '.substr($p, 2, 4).'-'.substr($p, 6),
            default => $phone,
        };
    }
}
