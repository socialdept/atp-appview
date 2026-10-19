<?php

namespace SocialDept\AtpAppview\Models;

use Illuminate\Database\Eloquent\Model;
use SocialDept\AtpAppview\Labels\Effect;

/**
 * An operator's decision about what one label value does, for content or
 * for accounts. labeler is a DID, or * for any labeler.
 *
 * @property int $id
 * @property string $labeler
 * @property string $value
 * @property string $target
 * @property Effect $effect
 */
class LabelPolicy extends Model
{
    public const ANY = '*';

    protected $table = 'atp_label_policies';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('atp-appview.index.connection') ?? parent::getConnectionName();
    }

    protected function casts(): array
    {
        return ['effect' => Effect::class];
    }
}
