<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Speaker HTTP integration tests skipped (requires a disposable database and HTTP server).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/bootstrap.php';
require_once $sourceDirectory . '/speaker_helpers.php';
$baseUrl = rtrim((string) (getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
if (!in_array(parse_url($baseUrl, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Use a loopback disposable HTTP server.');
}
function expectSpeakerHttp(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Speaker HTTP integration failed: ' . $message);
    }
}
function speakerHidden(string $html, string $name): string
{
    preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $match);
    expectSpeakerHttp(isset($match[1]), 'Missing form field ' . $name);
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}
$cookieFile = tempnam(sys_get_temp_dir(), 'dnr-speaker-http-');
$request = static function (string $path, ?array $post = null, array $headers = []) use ($baseUrl, $cookieFile): array {
    $curl = curl_init($baseUrl . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    if ($headers !== []) curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    if ($post !== null) {
        $multipart = isset($post['speaker_photo']) && $post['speaker_photo'] instanceof CURLFile;
        curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $response = curl_exec($curl);
    expectSpeakerHttp(is_string($response), 'HTTP request failed for ' . $path . ': ' . curl_error($curl));
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
};
$photoPath = tempnam(sys_get_temp_dir(), 'dnr-speaker-photo-');
$badPhotoPath = tempnam(sys_get_temp_dir(), 'dnr-speaker-not-photo-');
$fixtureImage = imagecreatetruecolor(640, 480);
imagefill($fixtureImage, 0, 0, imagecolorallocate($fixtureImage, 20, 120, 180));
imagepng($fixtureImage, $photoPath);
file_put_contents($badPhotoPath, '<?php echo "not an image";');
$userIds = [];
try {
    expectSpeakerHttp($request('speaker_photo.php?id=1')['status'] === 302, 'Photos require login.');
    expectSpeakerHttp($request('speakers.php')['status'] === 302, 'The directory requires login.');
    foreach (['editor', 'reviewer'] as $role) {
        $username = 'speaker-http-' . bin2hex(random_bytes(5));
        $password = bin2hex(random_bytes(16));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $username, $hash, $role);
        $stmt->execute();
        $userIds[] = (int) $conn->insert_id;
        $form = $request('login.php');
        $login = $request('login.php', ['csrf_token' => speakerHidden($form['body'], 'csrf_token'), 'username' => $username, 'password' => $password]);
        expectSpeakerHttp($login['status'] === 302, 'Test user can sign in.');
        $directory = $request('speakers.php');
        expectSpeakerHttp($directory['status'] === 200 && str_contains($directory['body'], 'Olivier Melnick'), 'Both roles can view speakers.');
        if ($role === 'editor') {
            $form = $request('edit_speaker.php');
            expectSpeakerHttp(speakerHidden($form['body'], 'phone_country_code') === '+1'
                && str_contains($form['body'], 'data-phone-number'), 'New speakers use the shared country picker and telephone input.');
            $input = ['csrf_token' => speakerHidden($form['body'], 'csrf_token'), 'name' => 'HTTP <Speaker>', 'email' => 'speaker-http@example.com', 'phone_country_code' => '+1', 'phone' => '(949) 400-2892', 'bio' => "Speaker biography\nVisit https://example.com/bio\n<script>alert(1)</script>"];
            foreach (SPEAKER_URL_FIELDS as $field => $label) {
                expectSpeakerHttp(str_contains($form['body'], 'name="' . $field . '"'), 'The form includes ' . $label . '.');
                $input[$field] = 'https://example.com/' . $field . '?from=profile&lang=en';
            }
            foreach (['name', 'email'] as $requiredField) {
                expectSpeakerHttp(str_contains($form['body'], 'for="speaker_' . $requiredField . '" class="required"'), 'Required speaker fields use the contact form label styling.');
                $missingRequired = $request('edit_speaker.php', array_replace($input, [$requiredField => '   ']));
                expectSpeakerHttp($missingRequired['status'] === 200 && str_contains($missingRequired['body'], 'Enter the speaker name, email address, and telephone number.'), 'The server rejects a missing ' . $requiredField . ' even without browser validation.');
            }
            expectSpeakerHttp($request('edit_speaker.php', array_replace($input, ['csrf_token' => 'bad']))['status'] === 400, 'Adding requires a valid CSRF token.');
            $created = $request('edit_speaker.php', $input);
            expectSpeakerHttp($created['status'] === 302, 'An editor can add a speaker.');
            preg_match('/Location: view_speaker.php\?id=(\d+)/i', $created['headers'], $match);
            expectSpeakerHttp(isset($match[1]), 'Saving redirects to the speaker record.');
            $speakerId = (int) $match[1];
            $view = $request('view_speaker.php?id=' . $speakerId);
            expectSpeakerHttp(str_contains($view['body'], 'HTTP &lt;Speaker&gt;') && !str_contains($view['body'], 'HTTP <Speaker>'), 'Speaker names are safely escaped.');
            expectSpeakerHttp(str_contains($view['body'], 'href="https://example.com/bio"')
                && str_contains($view['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
                && !str_contains($view['body'], '<script>alert(1)</script>'), 'Bios display safe links and escaped text like Contact Notes.');
            $edit = $request('edit_speaker.php?id=' . $speakerId);
            foreach (SPEAKER_URL_FIELDS as $field => $label) {
                expectSpeakerHttp(speakerHidden($edit['body'], $field) === $input[$field]
                    && str_contains($view['body'], 'href="' . htmlspecialchars($input[$field], ENT_QUOTES, 'UTF-8') . '"'), $label . ' persists and renders as an escaped link.');
                $input[$field] = 'https://example.com/updated/' . $field;
            }
            $input['books_url'] = '';
            expectSpeakerHttp(speakerHidden($edit['body'], 'phone_country_code') === '+1'
                && str_contains($edit['body'], 'value="(949) 400-2892"'), 'Existing phones are split into a country code and formatted local number.');
            $input['version'] = speakerHidden($edit['body'], 'version');
            $input['name'] = 'Updated HTTP speaker';
            $input['phone_country_code'] = '+44';
            $input['phone'] = '020 7946 0018';
            $input['bio'] = "Updated biography\nSecond line";
            $invalidLink = $request('edit_speaker.php?id=' . $speakerId, array_replace($input, ['donation_url' => 'javascript:alert(1)']));
            expectSpeakerHttp($invalidLink['status'] === 200 && str_contains($invalidLink['body'], 'Enter a valid Donation URL')
                && speakerHidden($invalidLink['body'], 'website_url') === $input['website_url']
                && str_contains($invalidLink['body'], $input['bio']), 'Invalid URLs preserve the other submitted fields.');
            $invalidPhone = $request('edit_speaker.php?id=' . $speakerId, array_replace($input, ['phone' => '123']));
            expectSpeakerHttp($invalidPhone['status'] === 200 && str_contains($invalidPhone['body'], 'Enter a valid Phone number')
                && speakerHidden($invalidPhone['body'], 'phone_country_code') === '+44'
                && str_contains($invalidPhone['body'], $input['bio']), 'Validation preserves the country selection and biography.');
            expectSpeakerHttp($request('edit_speaker.php?id=' . $speakerId, $input)['status'] === 302, 'An editor can edit a speaker.');
            $saved = $conn->query('SELECT phone, bio FROM speakers WHERE id = ' . $speakerId)->fetch_assoc();
            expectSpeakerHttp($saved['phone'] === '+442079460018' && $saved['bio'] === $input['bio'], 'Selected-country phone numbers and edited bios persist together.');
            $updatedSpeaker = fetchSpeaker($conn, $speakerId);
            foreach (SPEAKER_URL_FIELDS as $field => $label) {
                expectSpeakerHttp($updatedSpeaker[$field] === $input[$field], $label . ' can be updated or cleared.');
            }
            $conflict = $request('edit_speaker.php?id=' . $speakerId, $input);
            expectSpeakerHttp($conflict['status'] === 200 && str_contains($conflict['body'], 'changed in another session')
                && speakerHidden($conflict['body'], 'version') === $input['version'], 'A stale edit stays rejected across retries.');
            $currentForm = $request('edit_speaker.php?id=' . $speakerId);
            $input['version'] = speakerHidden($currentForm['body'], 'version');
            $uploadInput = $input + ['speaker_photo' => new CURLFile($photoPath, 'image/png', 'speaker.png')];
            expectSpeakerHttp($request('edit_speaker.php?id=' . $speakerId, $uploadInput)['status'] === 302, 'A valid photo is saved with the speaker.');
            $thumbnail = $request('speaker_photo.php?id=' . $speakerId);
            $full = $request('speaker_photo.php?id=' . $speakerId . '&size=full');
            $thumbSize = getimagesizefromstring($thumbnail['body']);
            $fullSize = getimagesizefromstring($full['body']);
            expectSpeakerHttp($thumbnail['status'] === 200 && max($thumbSize[0], $thumbSize[1]) <= 256
                && $full['status'] === 200 && $fullSize[0] === 640, 'Lists get thumbnails and detail pages get resized full photos.');
            preg_match('/ETag: (.+)\r/i', $thumbnail['headers'], $etag);
            expectSpeakerHttp(isset($etag[1]) && $request('speaker_photo.php?id=' . $speakerId, null, ['If-None-Match: ' . trim($etag[1])])['status'] === 304, 'Photo ETags support conditional requests.');
            expectSpeakerHttp($request('speaker_photo.php?id=' . $speakerId . '&size=full', null, ['If-None-Match: ' . trim($etag[1])])['status'] === 200, 'Thumbnail ETags do not suppress full photos.');
            $currentForm = $request('edit_speaker.php?id=' . $speakerId);
            $input['version'] = speakerHidden($currentForm['body'], 'version');
            $badUpload = $request('edit_speaker.php?id=' . $speakerId, $input + ['speaker_photo' => new CURLFile($badPhotoPath, 'image/png', 'fake.png')]);
            expectSpeakerHttp($badUpload['status'] === 200 && str_contains($badUpload['body'], 'Upload a JPEG, PNG, or WebP speaker photo'), 'Content validation rejects a forged image.');
            expectSpeakerHttp($request('speaker_photo.php?id=' . $speakerId)['body'] === $thumbnail['body'], 'Invalid uploads preserve the existing photo.');
            expectSpeakerHttp($request('edit_speaker.php?id=' . $speakerId, $input + ['remove_speaker_photo' => '1'])['status'] === 302, 'Editors can remove a photo while keeping the speaker.');
            $placeholder = $request('speaker_photo.php?id=' . $speakerId);
            expectSpeakerHttp(str_contains($placeholder['headers'], 'image/svg+xml')
                && str_contains($placeholder['body'], '>US</text>'), 'Photo removal restores the speaker initials.');
            expectSpeakerHttp($request('speaker_photo.php?id=2147483647')['status'] === 404, 'Missing speaker photos return 404.');

            $paginationPrefix = 'pagination-' . bin2hex(random_bytes(5));
            $addPageSpeaker = $conn->prepare('INSERT INTO speakers (name, email, phone) VALUES (?, ?, ?)');
            foreach (range(1, 101) as $number) {
                $pageName = $paginationPrefix . sprintf(' %03d', $number);
                $pageEmail = 'page' . $number . '@example.com';
                $pagePhone = '+19494002892';
                $addPageSpeaker->bind_param('sss', $pageName, $pageEmail, $pagePhone);
                $addPageSpeaker->execute();
                if (!in_array($number, [20, 21, 50, 51, 100, 101], true)) continue;
                $list = $request('speakers.php?q=' . urlencode($paginationPrefix) . '&per_page=20');
                $toolsCount = $number > 20 ? 2 : 0;
                expectSpeakerHttp(substr_count($list['body'], 'aria-label="Speaker pages"') === $toolsCount, 'Upper and lower pagination follow the Contacts visibility rule.');
                expectSpeakerHttp(substr_count($list['body'], 'class="contact-name-cell"') === 20, 'The directory respects its page size.');
                expectSpeakerHttp(substr_count($list['body'], '>50</a>') === ($number >= 21 ? 2 : 0)
                    && substr_count($list['body'], '>100</a>') === ($number >= 51 ? 2 : 0), 'Page-size choices appear at the same thresholds as Contacts.');
            }
            $addPageSpeaker->close();
            $lastPage = $request('speakers.php?q=' . urlencode($paginationPrefix) . '&per_page=50&page=3');
            expectSpeakerHttp(substr_count($lastPage['body'], 'class="contact-name-cell"') === 1
                && substr_count($lastPage['body'], 'Showing 101–101 of 101 speakers') === 2, 'Both pagination tools describe the last page accurately.');
            $descending = $request('speakers.php?q=' . urlencode($paginationPrefix) . '&per_page=20&sort_by=name&name_sort=desc');
            expectSpeakerHttp(strpos($descending['body'], $paginationPrefix . ' 101') < strpos($descending['body'], $paginationPrefix . ' 100'), 'Name sorting applies before pagination.');
            expectSpeakerHttp(str_contains($descending['body'], '+ New Speaker') && str_contains($descending['body'], 'aria-label="View speaker"')
                && str_contains($descending['body'], 'aria-label="Edit speaker"'), 'The directory exposes the requested button and both action icons.');
            $newEvent = $request('index.php');
            expectSpeakerHttp($newEvent['status'] === 200 && preg_match('/<select[^>]*name="presentations\[1\]\[speaker_id\]"/', $newEvent['body']) === 1
                && str_contains($newEvent['body'], 'Updated HTTP speaker'), 'New presentation forms use the current speaker directory.');
        } else {
            expectSpeakerHttp(!str_contains($directory['body'], 'href="edit_speaker.php'), 'Reviewers have no speaker mutation links.');
            expectSpeakerHttp($request('view_speaker.php?id=' . $speakerId)['status'] === 200, 'Reviewers can view speaker details.');
            expectSpeakerHttp($request('edit_speaker.php')['status'] === 403
                && $request('edit_speaker.php?id=' . $speakerId, ['name' => 'Forbidden'])['status'] === 403, 'Reviewer writes are rejected by the server.');
        }
        $request('logout.php', ['csrf_token' => speakerHidden($directory['body'], 'csrf_token')]);
    }
    // Speaker fixtures deliberately remain: the application cannot delete speakers.
    echo "Speaker HTTP integration tests passed.\n";
} finally {
    foreach ($userIds as $userId) {
        $conn->query('DELETE FROM users WHERE id = ' . $userId);
    }
    unlink($photoPath);
    unlink($badPhotoPath);
    if (is_file($cookieFile)) {
        unlink($cookieFile);
    }
}
