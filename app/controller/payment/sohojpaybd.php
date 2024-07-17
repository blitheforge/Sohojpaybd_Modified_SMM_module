<?php
if (!defined('PAYMENT')) {
    http_response_code(404);
    die();
}

$transaction_id = $_REQUEST['transactionId'];

if (empty($transaction_id)) {
    $up_response = file_get_contents('php://input');
    $up_response_decode = json_decode($up_response, true);
    $transaction_id = $up_response_decode['transaction_id'];
}

if (empty($transaction_id)) {
    errorExit("Direct access is not allowed m.");
}

$apiKey =  trim($methodExtras['api_key']);
$apiUrl = "https://secure.sohojpaybd.com/api/payment/verify";

$transaction_id = [
    'transaction_id' => $transaction_id
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $apiUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_POSTFIELDS => json_encode($transaction_id),
    CURLOPT_HTTPHEADER => [
        "SOHOJPAY-API-KEY: " . $apiKey,
        "content-type: application/json"
    ],
]);

$response = curl_exec($curl);
$err = curl_error($curl);

curl_close($curl);

if ($err) {
    errorExit("cURL Error #:" . $err);
}


if (empty($response)) {
    errorExit("Invalid Response From Payment API.");
}

$data = json_decode($response, true);

if (!isset($data['status']) && !isset($data['metadata']['order_id'])) {
    errorExit("Invalid Response From Payment API.");
}

if (isset($data['status']) && $data['status'] == 'COMPLETED') {
    $me = json_decode($data['metadata'], true);
    $orderId = $me['order_id'];
    $paymentDetails = $conn->prepare("SELECT * FROM payments WHERE payment_extra=:orderId");
    $paymentDetails->execute([
        "orderId" => $orderId
    ]);

    if ($paymentDetails->rowCount()) {


        $paymentDetails = $paymentDetails->fetch(PDO::FETCH_ASSOC);


        $row = $conn->prepare("SELECT * FROM clients WHERE client_id=:id");
        $row->execute(array("id" => $paymentDetails["client_id"]));
        $user = $row->fetch(PDO::FETCH_ASSOC);


        $_SESSION["msmbilisim_userlogin"]      = 1;
        $_SESSION["msmbilisim_userid"]         = $user["client_id"];
        $_SESSION["msmbilisim_userpass"]       = $user["password"];


        if (
            !countRow([
                'table' => 'payments',
                'where' => [
                    'client_id' => $user['client_id'],
                    'payment_method' => $methodId,
                    'payment_status' => 3,
                    'payment_delivery' => 2,
                    'payment_extra' => $orderId
                ]
            ])
        ) {
            $paidAmount = floatval($paymentDetails["payment_amount"]);
            if ($paymentFee > 0) {
                $fee = ($paidAmount * ($paymentFee / 100));
                $paidAmount -= $fee;
            }
            if ($paymentBonusStartAmount != 0 && $paidAmount > $paymentBonusStartAmount) {
                $bonus = $paidAmount * ($paymentBonus / 100);
                $paidAmount += $bonus;
            }

            $update = $conn->prepare('UPDATE payments SET 
                    client_balance=:balance,
                    payment_status=:status, 
                    payment_delivery=:delivery WHERE payment_id=:id');
            $update->execute([
                'balance' => $user["balance"],
                'status' => 3,
                'delivery' => 2,
                'id' => $paymentDetails['payment_id']
            ]);

            $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id');
            $balance->execute([
                "balance" => $user["balance"] + $paidAmount,
                "id" => $user["client_id"]
            ]);

            header("Location: " . site_url("addfunds"));
            exit();
        } else {
            errorExit("Order ID is already used.");
        }
    } else {
        errorExit("Order ID not found.");
    }
}

http_response_code(405);
die();
