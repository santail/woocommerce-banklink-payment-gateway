<?php

namespace Banklink;

use Banklink\Protocol\iPizza;

/**
 * Banklink implementation for Sampo bank using iPizza protocol for communication
 * For specs see https://www.danskebank.ee/public/documents/Pangalink_makse_spetsifikatsioon_eng.pdf
 *               https://www.danskebank.ee/public/documents/Pangalink_autentimise_spetsifikatsioon_eng.pdf
 *
 * @author Roman Marintsenko <inoryy@gmail.com>
 * @since  1.11.2012
 */
class DanskeBank extends Banklink
{
    protected $requestUrl = 'https://www2.danskebank.ee/ibank/pizza/pizza';
    protected $testRequestUrl = 'https://pangalink.net/banklink/sampo';

    /**
     * Force iPizza protocol
     *
     * @param \Banklink\iPizza $protocol
     * @param boolean          $testMode
     * @param string | null    $requestUrl
     */
    public function __construct(iPizza $protocol, $testMode = false, $requestUrl = null)
    {
        parent::__construct($protocol, $testMode, $requestUrl);
    }

    /**
     * No additional fields
     *
     * @see Banklink::getAdditionalFields()
     *
     * @return array
     */
    protected function getAdditionalFields()
    {
        return array();
    }
}