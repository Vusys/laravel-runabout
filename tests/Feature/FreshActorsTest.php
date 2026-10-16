<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * freshActors() reloads an actor's user before each request, as production
 * resolves it from the session. By default the actor's one instance is reused.
 */
final class FreshActorsTest extends TestCase
{
    use RunsJourneys;

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->get('/me', fn (Request $request): JsonResponse => new JsonResponse(['name' => $request->user()?->getAttribute('name')]));
    }

    public function test_fresh_actors_see_changes_made_behind_their_back(): void
    {
        $this->journey($this->journeyFor(User::query()->create(['name' => 'member']), renamed: 'renamed', deleted: null))
            ->freshActors()
            ->run();

        $this->addToAssertionCount(1);
    }

    public function test_actors_reuse_their_instance_by_default(): void
    {
        $this->journey($this->journeyFor(User::query()->create(['name' => 'member']), renamed: 'member', deleted: 'member'))->run();

        $this->addToAssertionCount(1);
    }

    public function test_an_unsaved_user_is_authenticated_as_it_is(): void
    {
        $this->journey(new class extends Journey
        {
            #[\Override]
            public function actors(): array
            {
                return ['ghost' => new User(['name' => 'ghost'])];
            }

            public function steps(): array
            {
                return [Step::make('ask')->act(fn (Context $ctx) => $ctx->as('ghost')->getJson('/me')->assertExactJson(['name' => 'ghost']))];
            }
        })->freshActors()->run();

        $this->addToAssertionCount(1);
    }

    public function test_it_is_a_no_op_without_an_http_driver(): void
    {
        (new PendingJourney(new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('run')->act(fn (): null => null)];
            }
        }, fn (Closure $trail) => $trail()))->freshActors()->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    private function journeyFor(User $member, string $renamed, ?string $deleted): Journey
    {
        return new class($member, $renamed, $deleted) extends Journey
        {
            public function __construct(private readonly User $member, private readonly string $renamed, private readonly ?string $deleted) {}

            #[\Override]
            public function actors(): array
            {
                return ['member' => $this->member];
            }

            public function steps(): array
            {
                return [
                    Step::make('change the member behind its back')->act(function (Context $ctx): void {
                        User::query()->whereKey($this->member->id)->update(['name' => 'renamed']);
                        $ctx->as('member')->getJson('/me')->assertExactJson(['name' => $this->renamed]);

                        User::query()->whereKey($this->member->id)->delete();
                        $ctx->as('member')->getJson('/me')->assertExactJson(['name' => $this->deleted]);
                    }),
                ];
            }
        };
    }
}
