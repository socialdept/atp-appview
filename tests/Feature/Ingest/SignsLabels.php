<?php

namespace SocialDept\AtpAppview\Tests\Feature\Ingest;

use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Math\BigInteger;
use SocialDept\AtpCbor\Core\CBOR;
use SocialDept\AtpSupport\Crypto\Base58;
use SocialDept\AtpSupport\Crypto\DidKey;

/**
 * Labels signed as a labeler signs them, with a throwaway secp256k1 key.
 */
trait SignsLabels
{
    /**
     * @param  array<string, mixed>  $label  Without sig
     */
    protected static function signLabel(PrivateKey $key, array $label): string
    {
        $order = new BigInteger('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141', 16);
        [$half] = $order->divide(new BigInteger(2));
        $signature = $key->withSignatureFormat('Raw')->withHash('sha256')->sign(CBOR::encode($label));
        $s = $signature['s']->compare($half) > 0 ? $order->subtract($signature['s']) : $signature['s'];

        return str_pad($signature['r']->toBytes(), 32, "\0", STR_PAD_LEFT).str_pad($s->toBytes(), 32, "\0", STR_PAD_LEFT);
    }

    protected static function didKey(PrivateKey $key): string
    {
        $point = $key->getPublicKey()->getEncodedCoordinates();

        return 'did:key:z'.Base58::encode(DidKey::SECP256K1_PREFIX.chr(0x02 | (ord($point[64]) & 1)).substr($point, 1, 32));
    }
}
