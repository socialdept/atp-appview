<?php

namespace SocialDept\AtpAppview\Standing\Verifiers;

use SocialDept\AtpAppview\Features\Moderation;
use SocialDept\AtpAppview\Labels\Labels;
use SocialDept\AtpAppview\Standing\Contracts\Verifier;
use SocialDept\AtpAppview\Standing\Verification;

/**
 * Verification by labels: a label value on the account from a labeler the
 * app follows, such as a community verifier publishing verified. Labels
 * reach the index through the moderation feature, and only those whose
 * signature it checked count. Without that feature nobody is verified this
 * way.
 */
class LabelVerifier implements Verifier
{
    /** @var array<int, string> */
    protected array $labelers;

    /** @var array<int, string> */
    protected array $values;

    /**
     * @param  array<string, mixed>  $options  labelers (DIDs), values
     */
    public function __construct(array $options = [])
    {
        $this->labelers = array_values(array_filter((array) ($options['labelers'] ?? []), 'is_string'));
        $this->values = array_values(array_filter((array) ($options['values'] ?? ['verified']), 'is_string'));
    }

    public function trusts(string $did): bool
    {
        return in_array($did, $this->labelers, true);
    }

    public function verify(array $dids): array
    {
        $results = [];

        foreach ($dids as $did) {
            if (! Moderation::on()) {
                $results[$did] = Verification::none('label');

                continue;
            }

            $verifications = app(Labels::class)->about($did)
                // Only labels whose signature the moderation pipeline checked.
                ->filter(fn ($label) => $label->verified && in_array($label->src, $this->labelers, true) && in_array($label->val, $this->values, true))
                ->map(fn ($label) => ['issuer' => $label->src, 'uri' => null, 'createdAt' => $label->cts, 'valid' => true, 'reason' => null])
                ->values()->all();

            $results[$did] = Verification::from($verifications, 'label');
        }

        return $results;
    }
}
