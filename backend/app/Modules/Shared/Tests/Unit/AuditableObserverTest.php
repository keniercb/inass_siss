<?php

declare(strict_types=1);

use App\Modules\Shared\Contracts\CurrentUserProviderInterface;
use App\Modules\Shared\Support\AuditableObserver;
use Illuminate\Database\Eloquent\Model;

it('stamps the acting user as creator on creating', function () {
    $model = auditableModel();
    $observer = new AuditableObserver(currentUser(7));

    $observer->creating($model);

    expect($model->getAttribute('created_by'))->toBe(7)
        ->and($model->getAttribute('updated_by'))->toBeNull();
});

it('never overwrites an author that was set explicitly', function () {
    $model = auditableModel(['created_by' => 42]);
    $observer = new AuditableObserver(currentUser(7));

    $observer->creating($model);

    expect($model->getAttribute('created_by'))->toBe(42);
});

it('ignores models that do not opt into audit authorship', function () {
    $model = new class extends Model
    {
        /** @var list<string> */
        protected $fillable = ['name'];
    };
    $observer = new AuditableObserver(currentUser(7));

    $observer->creating($model);
    $observer->updating($model);

    expect($model->getAttribute('created_by'))->toBeNull()
        ->and($model->getAttribute('updated_by'))->toBeNull();
});

it('leaves authorship empty on anonymous (CLI/seed) context', function () {
    $model = auditableModel();
    $observer = new AuditableObserver(currentUser(null));

    $observer->creating($model);

    expect($model->getAttribute('created_by'))->toBeNull();
});

it('stamps the last modifier on updating', function () {
    $model = auditableModel();
    $observer = new AuditableObserver(currentUser(9));

    $observer->updating($model);

    expect($model->getAttribute('updated_by'))->toBe(9);
});

it('keeps the previous modifier when updating anonymously', function () {
    $model = auditableModel(['updated_by' => 42]);
    $observer = new AuditableObserver(currentUser(null));

    $observer->updating($model);

    expect($model->getAttribute('updated_by'))->toBe(42);
});

// ---------------------------------------------------------------------------
// In-memory doubles: the unit contract of the observer needs no database,
// no container and no framework session (architecture doc section 8).
// ---------------------------------------------------------------------------

/**
 * @param  array<string, int|null>  $attributes
 */
function auditableModel(array $attributes = []): Model
{
    $model = new class extends Model
    {
        /** @var list<string> */
        protected $fillable = ['name', 'created_by', 'updated_by'];
    };

    $model->setRawAttributes($attributes, true);

    return $model;
}

function currentUser(?int $id): CurrentUserProviderInterface
{
    return new class($id) implements CurrentUserProviderInterface
    {
        public function __construct(private readonly ?int $id) {}

        public function currentUserId(): ?int
        {
            return $this->id;
        }
    };
}
