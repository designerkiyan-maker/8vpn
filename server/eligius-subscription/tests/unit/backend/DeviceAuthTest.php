<?php
/**
 * P6 unit (A-2) — Class_Device_Auth challenge/verify FLOW against the REAL
 * transient shim (run-tests.php: true per-key TTL, single-use via the class's
 * own delete-on-read):
 *   • happy path — ECDSA P-256 keypair generated at RUNTIME, signature verified;
 *   • replay — the challenge is consumed on the FIRST verify attempt whether
 *     or not the signature matched (code contract in class-device-auth.php);
 *   • expiry — the 60s TTL genuinely expires via clock travel (no fabricated
 *     "expired" fixture; a control proves a 59s-old challenge still verifies);
 *   • typed errors — mismatch, bad signature (wrong key + non-base64),
 *     unknown device.
 *
 * The device repository is STUBBED (unit scope has no database); the stub is
 * declared here and never conflicts with the real class (which is not loaded).
 * openssl is a default PHP extension (CI workflow installs it explicitly).
 */

namespace Eligius_Sub\Core {
    if (!class_exists('Eligius_Sub\Core\Class_Device_Repository')) {
        /**
         * Unit-scope stand-in for the real repository: Device_Auth only calls
         * find_by_hash() + touch(). Devices live in a static map keyed by
         * device hash (rows shaped like the real DB row: id/public_key/is_active).
         */
        class Class_Device_Repository {
            public static $devices = []; // device_hash => row object
            public static $touched = []; // device ids passed to touch()
            public static function find_by_hash($user_id, $device_hash) {
                return self::$devices[(string) $device_hash] ?? null;
            }
            public static function touch($device_id) {
                self::$touched[] = (int) $device_id;
            }
        }
    }
}

namespace {
    require_once __DIR__ . '/../../../includes/auth/class-device-auth.php';

    use Eligius_Sub\Auth\Class_Device_Auth;
    use Eligius_Sub\Core\Class_Device_Repository;

    final class DeviceAuthTest extends P6UnitBase {

        /** Fresh state per test: transients, clock, device map. */
        private function reset(): void {
            $GLOBALS['__p6_transients'] = [];
            $GLOBALS['__p6_clock'] = null;
            Class_Device_Repository::$devices = [];
            Class_Device_Repository::$touched = [];
        }

        /** Runtime ECDSA P-256 keypair → [private_key, public_PEM]. */
        private function keypair(): array {
            if (!is_array(self::$pool) || self::$idx >= count(self::$pool)) {
                self::$pool = $this->generate_batch();
                self::$idx  = 0;
            }
            $pair = self::$pool[self::$idx++] ?? null;
            if (!is_array($pair) || !isset($pair[1]) || '' === $pair[1]) {
                $this->check('openssl can generate an EC P-256 keypair in this environment', false);
                return ['', ''];
            }
            return $pair;
        }

        /**
         * Generate a batch of ECDSA P-256 keypairs in a CHILD php process.
         * Windows XAMPP OpenSSL needs an openssl.cnf for EC generation and
         * reads the config at library init, so in-process putenv() cannot fix
         * it; a spawned child inherits the parent env, so the parent sets
         * OPENSSL_CONF (harmless on Linux/CI where keygen works out of the
         * box) before the child runs. Keys never touch disk beyond the temp
         * script.
         */
        private function generate_batch(): array {
            if (false === getenv('OPENSSL_CONF')) {
                foreach (['E:/xampp/php/extras/ssl/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf'] as $cnf) {
                    if (file_exists($cnf)) { putenv('OPENSSL_CONF=' . $cnf); break; }
                }
            }
            $script = sys_get_temp_dir() . '/p6-ec-gen-' . getmypid() . '.php';
            file_put_contents($script, <<<'CODE'
<?php
$cfg = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
$out = [];
for ($i = 0; $i < 8; $i++) {
    $res = openssl_pkey_new($cfg);
    if (false === $res || !openssl_pkey_export($res, $priv)) { fwrite(STDERR, (string) openssl_error_string()); exit(1); }
    $out[] = [$priv, openssl_pkey_get_details($res)['key']];
}
echo json_encode($out);
CODE);
            $proc = proc_open(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $out = is_resource($proc) ? stream_get_contents($pipes[1]) : '';
            $err = is_resource($proc) ? stream_get_contents($pipes[2]) : '';
            if (is_resource($proc)) { fclose($pipes[1]); fclose($pipes[2]); proc_close($proc); }
            unlink($script);
            $pool = json_decode((string) $out, true);
            if (!is_array($pool) || !$pool) {
                if ('' !== trim((string) $err)) fwrite(STDERR, "EC keygen child failed: $err\n");
                return [];
            }
            return $pool;
        }

        private static $pool = null;
        private static $idx  = 0;

        /** SHA256withECDSA signature over $data, base64-encoded (the wire format). */
        private function sign_b64(string $priv, string $data): string {
            openssl_sign($data, $sig, $priv, OPENSSL_ALGO_SHA256);
            return base64_encode($sig);
        }

        private static function hash(string $seed): string {
            return str_pad(bin2hex(substr(hash('sha256', $seed, true), 0, 8)), 64, '0');
        }

        /** Register a device row in the stub repository. */
        private function device(string $hash, string $pub_pem, int $id = 7, bool $active = true): void {
            Class_Device_Repository::$devices[$hash] = (object) [
                'id' => $id, 'public_key' => $pub_pem, 'is_active' => $active ? 1 : 0,
            ];
        }

        public function testChallengeShapeAndTtl() {
            $this->reset();
            $c = Class_Device_Auth::instance()->create_challenge(101, self::hash('shape'));
            $this->check('challenge expires_in is exactly 60s (doc 03 §3.2)', 60 === $c['expires_in']);
            $raw = base64_decode($c['challenge'], true);
            $this->check('challenge decodes to 32 random bytes', false !== $raw && 32 === strlen($raw));
        }

        public function testHappyPathVerify() {
            $this->reset();
            [$priv, $pub] = $this->keypair();
            $h = self::hash('happy');
            $this->device($h, $pub, 7, true);
            $c = Class_Device_Auth::instance()->create_challenge(101, $h);

            $r = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'], $this->sign_b64($priv, $c['challenge']));
            $this->check('valid ECDSA signature over the challenge → verified:true', is_array($r) && true === $r['verified']);
            $this->check('verified result carries the device id and activity', is_array($r) && 7 === $r['device_id'] && true === $r['is_active']);
            $this->check('successful verify touches the device row', Class_Device_Repository::$touched === [7]);
        }

        public function testReplayRejectedSingleUse() {
            $this->reset();
            [$priv, $pub] = $this->keypair();
            $h = self::hash('replay');
            $this->device($h, $pub);
            $c = Class_Device_Auth::instance()->create_challenge(101, $h);
            $sig = $this->sign_b64($priv, $c['challenge']);

            $ok = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'], $sig);
            $this->check('first verify succeeds', is_array($ok) && true === $ok['verified']);

            $r = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'], $sig);
            $this->check('REPLAY (second use of the same challenge) → eligius_challenge_expired 422',
                is_wp_error($r) && 'eligius_challenge_expired' === $r->get_error_code() && 422 === $r->get_error_data()['status']);
        }

        public function testExpiredChallengeRejected() {
            $this->reset();
            [$priv, $pub] = $this->keypair();
            $h = self::hash('expired');
            $this->device($h, $pub);
            $c = Class_Device_Auth::instance()->create_challenge(101, $h);

            // Control: 59 seconds later (inside the TTL) it still verifies.
            $GLOBALS['__p6_clock'] = time() + 59;
            $ok = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'], $this->sign_b64($priv, $c['challenge']));
            $this->check('control: challenge 59s old still verifies (TTL is real, not fabricated)', is_array($ok) && true === $ok['verified']);

            // Now the failure case: a FRESH challenge (real clock!) aged past
            // its 60s TTL. The clock must return to real time first, or the
            // new challenge would inherit the shifted clock (+59) and expire
            // at +119 — never reaching the failure window.
            $GLOBALS['__p6_clock'] = null;
            $c2 = Class_Device_Auth::instance()->create_challenge(101, $h);
            $GLOBALS['__p6_clock'] = time() + 61;
            $r = Class_Device_Auth::instance()->verify_challenge(101, $h, $c2['challenge'], $this->sign_b64($priv, $c2['challenge']));
            $this->check('challenge older than 60s TTL → eligius_challenge_expired 422',
                is_wp_error($r) && 'eligius_challenge_expired' === $r->get_error_code() && 422 === $r->get_error_data()['status']);
        }

        public function testChallengeMismatchRejected() {
            $this->reset();
            [$priv, $pub] = $this->keypair();
            $h = self::hash('mismatch');
            $this->device($h, $pub);
            $c = Class_Device_Auth::instance()->create_challenge(101, $h);

            // The stored challenge is compared BEFORE any device/signature work.
            $r = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'] . 'x', 'not-even-a-signature');
            $this->check('challenge does not match stored value → eligius_challenge_mismatch 422',
                is_wp_error($r) && 'eligius_challenge_mismatch' === $r->get_error_code() && 422 === $r->get_error_data()['status']);
        }

        public function testWrongKeySignatureRejected() {
            $this->reset();
            [$priv_a, $pub_a] = $this->keypair();
            [, $pub_b] = $this->keypair();
            $h = self::hash('wrongkey');
            $this->device($h, $pub_a); // device registered with key A
            $c = Class_Device_Auth::instance()->create_challenge(101, $h);

            $r = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'], $this->sign_b64($priv_a, $c['challenge']));
            $this->check('sanity: signature of the REGISTERED key verifies', is_array($r) && true === $r['verified']);

            $h2 = self::hash('wrongkey2');
            $this->device($h2, $pub_b);
            $c2 = Class_Device_Auth::instance()->create_challenge(101, $h2);
            [$priv_other] = $this->keypair();
            $r2 = Class_Device_Auth::instance()->verify_challenge(101, $h2, $c2['challenge'], $this->sign_b64($priv_other, $c2['challenge']));
            $this->check('signature by a DIFFERENT key → eligius_bad_signature 422',
                is_wp_error($r2) && 'eligius_bad_signature' === $r2->get_error_code() && 422 === $r2->get_error_data()['status']);
        }

        public function testNonBase64SignatureRejected() {
            $this->reset();
            [, $pub] = $this->keypair();
            $h = self::hash('b64');
            $this->device($h, $pub);
            $c = Class_Device_Auth::instance()->create_challenge(101, $h);

            $r = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'], '%%% not base64 %%%');
            $this->check('signature that is not valid base64 → eligius_bad_signature 422',
                is_wp_error($r) && 'eligius_bad_signature' === $r->get_error_code() && 422 === $r->get_error_data()['status']);
        }

        public function testUnknownDeviceRejected() {
            $this->reset();
            [$priv] = $this->keypair();
            $h = self::hash('unknown');
            $c = Class_Device_Auth::instance()->create_challenge(101, $h);

            $r = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'], $this->sign_b64($priv, $c['challenge']));
            $this->check('no device row for the hash → eligius_device_not_found 404',
                is_wp_error($r) && 'eligius_device_not_found' === $r->get_error_code() && 404 === $r->get_error_data()['status']);
        }

        public function testFailedAttemptStillConsumesChallenge() {
            $this->reset();
            [$priv_ok, $pub] = $this->keypair();
            [$priv_bad] = $this->keypair();
            $h = self::hash('consume');
            $this->device($h, $pub);
            $c = Class_Device_Auth::instance()->create_challenge(101, $h);

            // First attempt: WRONG key → fails, but the code contract says the
            // challenge is consumed on the FIRST verify attempt either way.
            $bad = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'], $this->sign_b64($priv_bad, $c['challenge']));
            $this->check('first attempt with wrong key fails', is_wp_error($bad) && 'eligius_bad_signature' === $bad->get_error_code());

            // Second attempt: CORRECT signature, but the challenge is gone.
            $r = Class_Device_Auth::instance()->verify_challenge(101, $h, $c['challenge'], $this->sign_b64($priv_ok, $c['challenge']));
            $this->check('a FAILED first attempt still consumes the challenge (single-use)',
                is_wp_error($r) && 'eligius_challenge_expired' === $r->get_error_code());
        }

        public function testChallengeIsScopedPerUserAndDevice() {
            $this->reset();
            [$priv, $pub] = $this->keypair();
            $h = self::hash('scope');
            $this->device($h, $pub);
            $c = Class_Device_Auth::instance()->create_challenge(201, $h);

            // Verify under a DIFFERENT user id → different transient key → no
            // stored challenge there (cross-user replay is impossible).
            $r = Class_Device_Auth::instance()->verify_challenge(202, $h, $c['challenge'], $this->sign_b64($priv, $c['challenge']));
            $this->check('challenge is keyed per user|device → other user sees eligius_challenge_expired',
                is_wp_error($r) && 'eligius_challenge_expired' === $r->get_error_code());

            // The original (user 201) copy still verifies.
            $ok = Class_Device_Auth::instance()->verify_challenge(201, $h, $c['challenge'], $this->sign_b64($priv, $c['challenge']));
            $this->check('the issuing user can still verify its own challenge', is_array($ok) && true === $ok['verified']);
        }
    }
}
