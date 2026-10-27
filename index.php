<?php

$db = getenv('DB_PATH') ?: './data.db';
$dsn = "sqlite:{$db}";
require __DIR__ . '/vendor/autoload.php';

$redis = new Predis\Client([
    'scheme' => 'tcp',
    'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
    'port' => 6379,
]);

// Criar banco e inserir saldo inicial
function createDatabase() {
    global $dsn;

    $pdo = new PDO($dsn);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS balance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            amount REAL
        )
    ");

    $count = $pdo->query(
        "SELECT COUNT(*) FROM balance WHERE id = 1"
    )->fetchColumn();

    if ($count == 0) {
        $pdo->exec(
            "INSERT INTO balance (id, amount) VALUES (1, 100)"
        );
    }
}

//Obter saldo
function getBalance() {
    global $dsn;
    $pdo = new \PDO($dsn);

    try {
        $pdo = new \PDO($dsn);
        $result = $pdo->query("SELECT amount FROM balance LIMIT 1");
        return $result->fetch(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        echo $e->getMessage();
    }
}

//Função para vender um livro
function sellBook() {
    global $dsn;

    $price = 10;
    $pdo = new PDO($dsn);

    $balance = $pdo
        ->query("SELECT amount FROM balance WHERE id = 1")
        ->fetchColumn();

    // Janela de concorrência para demonstrar a vulnerabilidade
    usleep(500000);

    if ($balance >= $price) {
        $stmt = $pdo->prepare(
            "UPDATE balance SET amount = ? WHERE id = 1"
        );

        $stmt->execute([$balance - $price]);

        echo "Livro vendido (vulnerável)!\n";
    } else {
        echo "Saldo insuficiente para o livro.\n";
    }
}


//Função para vender um livro com lock no redis
function sellBookRedis() {
    global $dsn, $redis;

    $price = 10;
    $pdo = new PDO($dsn);
    $token = bin2hex(random_bytes(16));
    $lockKey = 'lock:balance';
    $locked = false;

    try {
        // Tenta adquirir o lock por até 5 segundos
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $result = $redis->executeRaw([
                'SET', $lockKey, $token, 'NX', 'PX', '10000'
            ]);

            if ($result === 'OK') {
                $locked = true;
                break;
            }

            usleep(100000);
        }

        if (!$locked) {
            echo "Não foi possível obter o lock.\n";
            return;
        }

        $balance = $pdo
            ->query("SELECT amount FROM balance WHERE id = 1")
            ->fetchColumn();

        // Mesma janela da versão vulnerável, agora protegida pelo lock
        usleep(500000);

        if ($balance >= $price) {
            $stmt = $pdo->prepare(
                "UPDATE balance SET amount = ? WHERE id = 1"
            );

            $stmt->execute([$balance - $price]);
            echo "Livro vendido (redis lock)!\n";
        } else {
            echo "Saldo insuficiente!\n";
        }

    } finally {
        if ($locked) {
            // Só libera o lock se ainda pertencer a esta requisição
            $redis->eval(
                "if redis.call('GET', KEYS[1]) == ARGV[1] then
                    return redis.call('DEL', KEYS[1])
                 else
                    return 0
                 end",
                1,
                $lockKey,
                $token
            );
        }
    }
}

//Função para vender uma revista com lock no redis
function sellMagazineRedis() {
    global $dsn, $redis;

    $price = 5;
    $pdo = new PDO($dsn);
    $token = bin2hex(random_bytes(16));
    $lockKey = 'lock:balance';
    $locked = false;

    try {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $result = $redis->executeRaw([
                'SET', $lockKey, $token, 'NX', 'PX', '10000'
            ]);

            if ($result === 'OK') {
                $locked = true;
                break;
            }

            usleep(100000);
        }

        if (!$locked) {
            echo "Não foi possível obter o lock.\n";
            return;
        }

        $balance = $pdo
            ->query("SELECT amount FROM balance WHERE id = 1")
            ->fetchColumn();

        usleep(500000);

        if ($balance >= $price) {
            $stmt = $pdo->prepare(
                "UPDATE balance SET amount = ? WHERE id = 1"
            );

            $stmt->execute([$balance - $price]);
            echo "Revista vendida (redis lock)!\n";
        } else {
            echo "Saldo insuficiente!\n";
        }

    } finally {
        if ($locked) {
            $redis->eval(
                "if redis.call('GET', KEYS[1]) == ARGV[1] then
                    return redis.call('DEL', KEYS[1])
                 else
                    return 0
                 end",
                1,
                $lockKey,
                $token
            );
        }
    }
}

//Função para vender um livro com lock
// function sellBook() {
//     global $dsn;

//     $price = 10;
//     $pdo = new PDO($dsn);
//     $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//     $pdo->exec("PRAGMA busy_timeout = 5000");

//     try {
//         $pdo->exec("BEGIN IMMEDIATE");

//         $balance = $pdo
//             ->query("SELECT amount FROM balance WHERE id = 1")
//             ->fetchColumn();

//         if ($balance >= $price) {
//             $stmt = $pdo->prepare(
//                 "UPDATE balance SET amount = ? WHERE id = 1"
//             );

//             $stmt->execute([$balance - $price]);
//             $pdo->commit();

//             echo "Livro vendido!\n";
//         } else {
//             $pdo->rollBack();
//             echo "Saldo insuficiente!\n";
//         }
//     } catch (PDOException $e) {
//         if ($pdo->inTransaction()) {
//             $pdo->rollBack();
//         }

//         echo "Erro na venda do livro: " . $e->getMessage() . "\n";
//     }
// }


//Função para vender uma revista com lock
// function sellMagazine() {
//     global $dsn;

//     $price = 5;
//     $pdo = new PDO($dsn);
//     $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
//     $pdo->exec("PRAGMA busy_timeout = 5000");

//     try {
//         $pdo->exec("BEGIN IMMEDIATE");

//         $balance = $pdo
//             ->query("SELECT amount FROM balance WHERE id = 1")
//             ->fetchColumn();

//         if ($balance >= $price) {
//             $stmt = $pdo->prepare(
//                 "UPDATE balance SET amount = ? WHERE id = 1"
//             );

//             $stmt->execute([$balance - $price]);
//             $pdo->commit();

//             echo "Revista vendida!\n";
//         } else {
//             $pdo->rollBack();
//             echo "Saldo insuficiente!\n";
//         }
//     } catch (PDOException $e) {
//         if ($pdo->inTransaction()) {
//             $pdo->rollBack();
//         }

//         echo "Erro na venda da revista: " . $e->getMessage() . "\n";
//     }
// }

//Função para vender uma revista
function sellMagazine() {
    global $dsn;

    $price = 5;
    $pdo = new PDO($dsn);

    $balance = $pdo
        ->query("SELECT amount FROM balance WHERE id = 1")
        ->fetchColumn();

    usleep(500000);

    if ($balance >= $price) {
        $stmt = $pdo->prepare(
            "UPDATE balance SET amount = ? WHERE id = 1"
        );

        $stmt->execute([$balance - $price]);

        echo "Revista vendida (vulnerável)!\n";
    } else {
        echo "Saldo insuficiente para a revista.\n";
    }
}

//Função para vender um livro de forma segura
function sellBookSafe() {
    global $dsn;

    $pdo = new PDO($dsn);
    $price = 10;

    $stmt = $pdo->prepare("
        UPDATE balance
        SET amount = amount - :price
        WHERE id = 1 AND amount >= :price
    ");

    $stmt->execute(['price' => $price]);

    if ($stmt->rowCount() === 1) {
        echo "Livro vendido (seguro)!\n";
    } else {
        echo "Saldo insuficiente para o livro.\n";
    }
}

//Função para vender uma revista de forma segura
function sellMagazineSafe() {
    global $dsn;

    $pdo = new PDO($dsn);
    $price = 5;

    $stmt = $pdo->prepare("
        UPDATE balance
        SET amount = amount - :price
        WHERE id = 1 AND amount >= :price
    ");

    $stmt->execute(['price' => $price]);

    if ($stmt->rowCount() === 1) {
        echo "Revista vendida (seguro)!\n";
    } else {
        echo "Saldo insuficiente para a revista.\n";
    }
}

createDatabase();

header('Content-Type: text/plain; charset=utf-8');

// Rotas: index.php?action=book | magazine | book-safe | magazine-safe | book-redis | magazine-redis | reset
$actions = [
    'book' => 'sellBook',                   // Vulnerável
    'magazine' => 'sellMagazine',           // Vulnerável
    'book-safe' => 'sellBookSafe',
    'magazine-safe' => 'sellMagazineSafe',
    'book-redis' => 'sellBookRedis',
    'magazine-redis' => 'sellMagazineRedis',
];

$action = $_GET['action'] ?? '';

if ($action === 'reset') {
    (new PDO($dsn))->exec("UPDATE balance SET amount = 100 WHERE id = 1");
} elseif (isset($actions[$action])) {
    $actions[$action]();
}

$balance = getBalance();
echo "Saldo atual: " . $balance['amount'] . "\n";
