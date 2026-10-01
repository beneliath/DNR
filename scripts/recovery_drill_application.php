<?php
declare(strict_types=1);

// This driver is mounted only in the isolated replacement-host drill.
if (getenv('DNR_RESTORE_DRILL') !== 'isolated') {
    throw new RuntimeException('An isolated restore drill is required.');
}
require '/var/www/html/bootstrap.php';
require '/var/www/html/two_factor_helpers.php';
require '/var/www/html/key_rotation_helpers.php';

foreach (APPLICATION_ENCRYPTED_COLUMNS as $table => $column) {
    $cursor = 0;
    do {
        $rows = $conn->execute_query("SELECT id, `$column` AS payload FROM `$table` WHERE id>? AND `$column` IS NOT NULL AND `$column`<>'' ORDER BY id LIMIT 100", [$cursor])->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $row) {
            $plaintext = Dnr\Security\ApplicationKey::open($row['payload']);
            sodium_memzero($plaintext);
            $cursor = (int)$row['id'];
        }
    } while (count($rows) === 100);
}

// Exercise the real password/MFA flow with a new synthetic account. Original
// users, passwords and encrypted secrets are preserved in the restored copy.
$username = 'restore-drill-' . bin2hex(random_bytes(8));
$password = 'Drill!' . bin2hex(random_bytes(24));
$conn->execute_query("INSERT INTO users (username,password,role,email,account_status,email_verified_at) VALUES (?,?,'admin',?,'active',UTC_TIMESTAMP())", [$username,password_hash($password,PASSWORD_DEFAULT),$username.'@example.invalid']);
$userId = (int)$conn->insert_id;
$secret = generateTotpSecret();
$user = fetchAuthenticationUserById($conn,$userId);
enableTwoFactorForUser($conn,$userId,$secret,0,(int)$user['auth_version']);
$cookie = tempnam('/tmp','restore-cookie-');
$request = static function(string $path, ?array $fields = null) use ($cookie): array {
    $curl = curl_init('http://127.0.0.1/'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30]);
    if ($fields !== null) curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields)]);
    $result = curl_exec($curl);
    if ($result === false) throw new RuntimeException('Replacement HTTP request failed.');
    $size = curl_getinfo($curl,CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status'=>$status,'headers'=>substr($result,0,$size),'body'=>substr($result,$size)];
};
$csrf = static function(array $response): string {
    if (!preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$response['body'],$match)) throw new RuntimeException('Replacement form unavailable.');
    return html_entity_decode($match[1],ENT_QUOTES);
};
try {
    $login = $request('login.php');
    $response = $request('login.php',['csrf_token'=>$csrf($login),'username'=>$username,'password'=>$password]);
    if (!str_contains($response['headers'],'Location: verify_2fa.php')) throw new RuntimeException('Replacement password login failed.');
    $form = $request('verify_2fa.php');
    $response = $request('verify_2fa.php',['csrf_token'=>$csrf($form),'authentication_code'=>createTotp($secret,$username)->now()]);
    if (!str_contains($response['headers'],'Location: dashboard.php')) throw new RuntimeException('Replacement MFA login failed.');
    foreach (['dashboard.php','organizations.php','contacts.php','engagements.php'] as $path) {
        if ($request($path)['status'] !== 200) throw new RuntimeException('A replacement record page is unavailable.');
    }
    echo json_encode(['encryption_verified'=>true,'administrator_password_and_mfa_verified'=>true,'record_pages_verified'=>true],JSON_THROW_ON_ERROR)."\n";
} finally {
    unlink($cookie);
    $conn->execute_query('DELETE FROM users WHERE id=?',[$userId]);
}
