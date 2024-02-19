<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__ . "./../");
$dotenv->load();

$dbHost = $_ENV['DB_HOSTNAME'];
$dbName = $_ENV['DB_NAME'];
$dbUser = $_ENV['DB_USER'];
$dbPass = $_ENV['DB_PASSWORD'];

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $error) {
    echo 'Connection failed: ' . $error->getMessage();
}

$app = AppFactory::create();

$app->addErrorMiddleware(true, true, true);

$app->post('/clientes/{id}/transacoes', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];
    $body = json_decode($request->getBody()->getContents(), true);

    if (!isset($body['tipo']) || !isset($body['valor']) || !isset($body['descricao'])) {
        $response->getBody()
            ->write(json_encode([
                'error' => [
                    'message' => 'Parâmetros inválidos',
                    'code' => 400
                ]
            ], JSON_PRETTY_PRINT
        ));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    if ($body['tipo'] !== 'd' && $body['tipo'] !== 'c') {
        $response->getBody()
            ->write(json_encode([
                'error' => [
                    'message' => 'Tipo de transação inválido',
                    'code' => 400
                ]
            ], JSON_PRETTY_PRINT
        ));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    if (!is_numeric($body['valor']) || $body['valor'] <= 0) {
        $response->getBody()
            ->write(json_encode([
                'error' => [
                    'message' => 'Valor inválido',
                    'code' => 400
                ]
            ], JSON_PRETTY_PRINT
        ));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }

    $query = 'SELECT * FROM clientes WHERE id = ?';
    $statement = $pdo->prepare($query);
    $statement->execute([$id]);
    $cliente = $statement->fetch();

    if (!$cliente) {
        $response->getBody()
            ->write(json_encode([
                'error' => [
                    'message' => 'Cliente não encontrado',
                    'code' => 404
                ]
            ], JSON_PRETTY_PRINT
        ));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    if ($body['tipo'] === 'd') {
        $saldoAtualizado = $cliente['saldo'] - $body['valor'];

        if ($saldoAtualizado < 0 && ($saldoAtualizado < $cliente['limite'] * -1)) {
            $response->getBody()
                ->write(json_encode([
                    'error' => [
                        'message' => 'Limite de saldo excedido',
                        'code' => 422
                    ]
                ], JSON_PRETTY_PRINT
            ));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
        }
    }

    try {
        $pdo->beginTransaction();

        $query = 'INSERT INTO transacoes SET cliente_id = ?, valor = ?, tipo = ?, descricao = ?';
        $statement = $pdo->prepare($query);
        $statement->execute([$id, $body['valor'], $body['tipo'], $body['descricao']]);

        if ($body['tipo'] === 'd') {
            $query = 'UPDATE clientes SET saldo = saldo - ? WHERE id = ?';
            $statement = $pdo->prepare($query);
            $statement->execute([$body['valor'], $id]);
        }

        if ($body['tipo'] === 'c'){
            $query = 'UPDATE clientes SET saldo = saldo + ? WHERE id = ?';
            $statement = $pdo->prepare($query);
            $statement->execute([$body['valor'], $id]);
        }

        $pdo->commit();

    } catch (\Exception $exception) {
        $pdo->rollBack();

        $response->getBody()
            ->write(json_encode([
                'error' => [
                    'message' => $exception->getMessage(),
                    'code' => 500
                ]
            ], JSON_PRETTY_PRINT
        ));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
    }

    $query = 'SELECT * FROM clientes WHERE id = ?';
    $statement = $pdo->prepare($query);
    $statement->execute([$id]);
    $cliente = $statement->fetch();

    $response->getBody()
        ->write(json_encode([
            'limite' => $cliente['limite'],
            'saldo' => $cliente['saldo']
        ], JSON_PRETTY_PRINT
    ));

    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

$app->get('/clientes/{id}/extrato', function (Request $request, Response $response, $args) use ($pdo) {
    $id = $args['id'];

    $query = 'SELECT * FROM clientes WHERE id = ?';
    $statement = $pdo->prepare($query);
    $statement->execute([$id]);
    $cliente = $statement->fetch();

    if (!$cliente) {
        $response->getBody()
            ->write(json_encode([
                'error' => [
                    'message' => 'Cliente não encontrado',
                    'code' => 404
                ]
            ], JSON_PRETTY_PRINT
        ));

        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    $query = 'SELECT * FROM transacoes WHERE cliente_id = ? ORDER BY id DESC LIMIT 10';
    $statement = $pdo->prepare($query);
    $statement->execute([$id]);
    $ultimasTransacoes = $statement->fetchAll(\PDO::FETCH_ASSOC);

    $response->getBody()
        ->write(json_encode([
            'saldo' => [
                'total' => $cliente['saldo'],
                'data_extrato' => (new \DateTime())->format('Y-m-d\TH:i:s.u\Z'),
                'limite' => $cliente['limite'],
            ],
            'ultimas_transacoes' => array_map(function ($transacao) {
                return [
                    'valor' => $transacao['valor'],
                    'tipo' => $transacao['tipo'],
                    'descricao' => $transacao['descricao'],
                    'realizada_em' => (new \DateTime($transacao['realizada_em']))->format('Y-m-d\TH:i:s.u\Z')
                ];
            }, $ultimasTransacoes)
        ], JSON_PRETTY_PRINT
    ));

    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

$app->run();