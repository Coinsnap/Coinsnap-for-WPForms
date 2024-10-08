<?php
declare(strict_types=1);
namespace Coinsnap\Result;

if (!defined('ABSPATH')) {
    exit;
}

class ServerInfo extends AbstractResult {
    public function getVersion(): string {
        return $this->getData()['version'];
    }

    //  @return string
    public function getOnionUrl(): string {
        return $this->getData()['onion'];
    }

    public function isFullySynced(): bool {
        return $this->getData()['fullySynched'];
    }

    //  @return string[] Example: ['Bitcoin', 'Bitcoin_LightningLike']
    public function getSupportedPaymentMethods(): array {
        return $this->getData()['supportedPaymentMethods'];
    }

    // TODO add "syncStatus" structure
}
