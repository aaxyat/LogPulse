<?php
declare(strict_types=1);

namespace LogPulse\Services;

/**
 * Zero-Dependency Encrypted Configuration Vault
 * Implements authenticated AES-256-GCM encryption for application credentials.
 * Eliminates plaintext .env files on disk.
 */
class ConfigVault
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    private static ?array $cachedConfig = null;

    public static function getEncryptedConfigPath(): string
    {
        return dirname(__DIR__, 2) . '/config/config.enc';
    }

    public static function getMasterKeyPath(): string
    {
        return dirname(__DIR__, 2) . '/config/.master_key';
    }

    public static function getFallbackConfigPath(): string
    {
        return dirname(__DIR__, 2) . '/config/config.php';
    }

    /**
     * Check if the application is fully configured
     */
    public static function isConfigured(): bool
    {
        if (file_exists(self::getEncryptedConfigPath()) && self::getMasterKey() !== null) {
            $config = self::load();
            return !empty($config['database']) && !empty($config['database']['driver']);
        }

        // Fallback to legacy config.php if present and valid
        if (file_exists(self::getFallbackConfigPath())) {
            $config = require self::getFallbackConfigPath();
            return is_array($config) && !empty($config['database']);
        }

        return false;
    }

    /**
     * Retrieve the master encryption key from environment or key file
     */
    public static function getMasterKey(): ?string
    {
        // 1. Environment variable override
        $envKey = getenv('LOGPULSE_KEY');
        if (!empty($envKey)) {
            return trim($envKey);
        }

        // 2. Key file on disk
        $keyFile = self::getMasterKeyPath();
        if (file_exists($keyFile) && is_readable($keyFile)) {
            $content = trim(file_get_contents($keyFile));
            if (!empty($content)) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Generate a cryptographically secure 256-bit master encryption key
     */
    public static function generateMasterKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /**
     * Save the master encryption key to disk with restricted permissions
     */
    public static function saveMasterKey(string $key): bool
    {
        $keyPath = self::getMasterKeyPath();
        $written = @file_put_contents($keyPath, trim($key), LOCK_EX);
        if ($written !== false) {
            @chmod($keyPath, 0600);
            return true;
        }
        return false;
    }

    /**
     * Encrypt an associative configuration array using AES-256-GCM
     */
    public static function encrypt(array $data, string $base64Key): string
    {
        $rawKey = base64_decode($base64Key);
        if (strlen($rawKey) !== 32) {
            throw new \InvalidArgumentException("Invalid master key length: must be 256 bits (32 bytes).");
        }

        $iv = random_bytes(self::IV_LEN);
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        
        $tag = '';
        $ciphertext = openssl_encrypt(
            $json,
            self::CIPHER,
            $rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new \RuntimeException("OpenSSL encryption failure: " . openssl_error_string());
        }

        // Binary package: IV (12 bytes) + TAG (16 bytes) + CIPHERTEXT
        return $iv . $tag . $ciphertext;
    }

    /**
     * Decrypt a binary package into a configuration array using AES-256-GCM
     */
    public static function decrypt(string $payload, string $base64Key): ?array
    {
        $rawKey = base64_decode($base64Key);
        if (strlen($rawKey) !== 32) {
            return null;
        }

        if (strlen($payload) < (self::IV_LEN + self::TAG_LEN)) {
            return null;
        }

        $iv = substr($payload, 0, self::IV_LEN);
        $tag = substr($payload, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($payload, self::IV_LEN + self::TAG_LEN);

        $decrypted = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $rawKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            return null;
        }

        $decoded = json_decode($decrypted, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Encrypt and save configuration to disk
     */
    public static function save(array $config, ?string $base64Key = null): bool
    {
        if ($base64Key === null) {
            $base64Key = self::getMasterKey();
            if ($base64Key === null) {
                $base64Key = self::generateMasterKey();
                self::saveMasterKey($base64Key);
            }
        } else {
            self::saveMasterKey($base64Key);
        }

        $binary = self::encrypt($config, $base64Key);
        $encPath = self::getEncryptedConfigPath();

        $saved = @file_put_contents($encPath, $binary, LOCK_EX);
        if ($saved !== false) {
            @chmod($encPath, 0600);
            self::$cachedConfig = $config;
            return true;
        }

        return false;
    }

    /**
     * Load and decrypt configuration into memory
     */
    public static function load(): array
    {
        if (self::$cachedConfig !== null) {
            return self::$cachedConfig;
        }

        // Try encrypted config first
        $encPath = self::getEncryptedConfigPath();
        $key = self::getMasterKey();

        if (file_exists($encPath) && $key !== null) {
            $payload = @file_get_contents($encPath);
            if ($payload !== false) {
                $decrypted = self::decrypt($payload, $key);
                if (is_array($decrypted)) {
                    self::$cachedConfig = $decrypted;
                    return self::$cachedConfig;
                }
            }
        }

        // Fallback to legacy config.php if present
        $fallbackPath = self::getFallbackConfigPath();
        if (file_exists($fallbackPath)) {
            $config = require $fallbackPath;
            if (is_array($config)) {
                self::$cachedConfig = $config;
                return self::$cachedConfig;
            }
        }

        return [];
    }

    public static function clearCache(): void
    {
        self::$cachedConfig = null;
    }
}
