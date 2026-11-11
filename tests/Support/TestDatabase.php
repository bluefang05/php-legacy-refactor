<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;

final class TestDatabase
{
    public static function create(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/schema.sql'));

        return $pdo;
    }

    /**
     * @param list<array{0:string,1:string,2:float,3:float,4:float,5:string}> $orders
     *        [customer, status, subtotal, tax_rate, discount, created_at]
     */
    public static function seed(PDO $pdo, array $orders): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO orders (customer, status, subtotal, tax_rate, discount, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($orders as $order) {
            $stmt->execute($order);
        }
    }
}
