<?php

declare(strict_types=1);

namespace Plugin\AiChatAssistant42\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Plugin\AiChatAssistant42\Service\ApiKeyEncryptor;

class ApiKeyEncryptorTest extends TestCase
{
    private ApiKeyEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = new ApiKeyEncryptor('test_app_secret_1234567890');
    }

    public function testEncryptAndDecryptRoundTrip(): void
    {
        $plain = 'sk-proj-test1234567890abcdef';
        $encrypted = $this->encryptor->encrypt($plain);
        $this->assertNotEquals($plain, $encrypted);
        $this->assertTrue($this->encryptor->isEncrypted($encrypted));
        $decrypted = $this->encryptor->decrypt($encrypted);
        $this->assertEquals($plain, $decrypted);
    }

    public function testDecryptPlainTextReturnsPlain(): void
    {
        $plain = 'sk-test-plain-key';
        $this->assertFalse($this->encryptor->isEncrypted($plain));
        $this->assertEquals($plain, $this->encryptor->decrypt($plain));
    }

    public function testEncryptEmptyReturnsEmpty(): void
    {
        $this->assertEquals('', $this->encryptor->encrypt(''));
        $this->assertEquals('', $this->encryptor->decrypt(''));
        $this->assertFalse($this->encryptor->isEncrypted(''));
    }

    public function testDifferentKeysProduceDifferentCiphertexts(): void
    {
        $plain = 'sk-same-key';
        $enc1 = $this->encryptor->encrypt($plain);
        $enc2 = $this->encryptor->encrypt($plain);
        // nonce がランダムなため、同じ平文でも異なる暗号文になる
        $this->assertNotEquals($enc1, $enc2);
        $this->assertEquals($plain, $this->encryptor->decrypt($enc1));
        $this->assertEquals($plain, $this->encryptor->decrypt($enc2));
    }

    public function testDecryptWithWrongKeyReturnsEncrypted(): void
    {
        $plain = 'sk-secret';
        $encrypted = $this->encryptor->encrypt($plain);
        $other = new ApiKeyEncryptor('different_secret');
        // 異なる鍵で復号すると失敗し、暗号文がそのまま返る（後方互換）
        $this->assertEquals($encrypted, $other->decrypt($encrypted));
    }

    public function testIsEncryptedReturnsFalseForPlainBase64Like(): void
    {
        // 平文が base64 っぽくても、長さや再エンコードで判定
        $plain = 'sk-1234';
        $this->assertFalse($this->encryptor->isEncrypted($plain));
    }
}
