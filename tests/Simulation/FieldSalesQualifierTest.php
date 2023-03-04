<?php

namespace Tests\Simulation;

use App\Notifications\SendAgentOnboardingNotification;
use App\Notifications\SendRegisterUserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Notification;
use App\Http\Middleware\VerifyCsrfToken;
use App\Actions\RegisterOrganization;
use App\Models\OrganizationUser;
use Illuminate\Support\Arr;
use App\Enums\ChannelEnum;
use App\Enums\FormatEnum;
use App\Models\Package;
use App\Models\Voucher;
use App\Models\User;
use Tests\TestCase;

class FieldSalesQualifierTest extends TestCase
{
    use WithFaker, RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    /** @test */
    public function system_user_is_seeded_with_balance()
    {
        $this->assertNotNull($system = User::getSystem());
        $this->assertEquals(config('domain.default.system.deposit'), $system->balanceFloat);
    }

    /** @test */
    public function enterprise_user_can_register_online()
    {
        $response = $this->withoutMiddleware([VerifyCsrfToken::class])
            ->post('/register', [
            'name' => $this->faker->name(),
            'email' => $email = $this->faker->email(),
            'mobile' => $this->faker->mobileNumber(),
            'password' => 'password', 'password_confirmation' => 'password', 'terms' => true,
            ], ['Accept' => 'application/json']
        );
        $response->assertSuccessful();
        $this->assertAuthenticated();
        tap(app(User::class)->where(compact('email'))->first(), function ($user) {
            $this->assertNotNull($user);
        });
    }

    /** @test */
    public function registered_enterprise_user_can_organize_an_agent_registration_campaign_online()
    {
        Notification::fake();
        $this->assertNotNull(app(Package::class)->where('code', 'qualification')->first());
        $enterprise_user = User::factory()->create();
        $response = $this->postJson("/api/register-organization", [
            'name' => $this->faker->company(),
            'channel' => ChannelEnum::SMS,
            'format' => FormatEnum::TXT,
            'address' => $enterprise_email = $this->faker->email(),
            'command' => $text = 'The quick brown fox jumps over the lazy dog.',
            'package' => 'qualification'
        ], [
            'Authorization' => 'Bearer ' . $enterprise_user->createToken('mobile')->plainTextToken
        ]);
        $response->assertSuccessful();

        $code = $response->json('code');
        $voucher = tap(app(Voucher::class)->where('code', $code)->first(), function ($voucher) use ($enterprise_user, $enterprise_email, $text) {
            tap($voucher->campaign, function ($campaign) use ($enterprise_user, $enterprise_email, $text) {
                tap($campaign->repository, function($repository) use ($enterprise_user, $enterprise_email, $text) {
                    $this->assertEquals(ChannelEnum::SMS, $repository->channel);
                    $this->assertEquals(FormatEnum::TXT, $repository->format);
                    $this->assertEquals($enterprise_email, $repository->address);
                    $this->assertEquals($text, $repository->command);
                    $this->assertTrue($enterprise_user->is($repository->organization->admin));
                });
                $this->assertEquals('qualification', $campaign->package->code);
            });
        });
        $this->assertEquals($code, $voucher->code);
        Notification::assertSentTo($enterprise_user, SendRegisterUserNotification::class, function (SendRegisterUserNotification $notification) use ($voucher) {
            $user = $notification->voucher->campaign->repository->organization->admin;
            $org = rtrim($notification->voucher->campaign->repository->organization->name, '.');
            $url = route('create-recruit', ['voucher' => $notification->voucher->code]);
            return
                $voucher->is($notification->voucher)
                && $notification->getContent($user) == trans('domain.org-campaign', ['org' => $org, 'url' => $url]);
        });
    }

    /** @test */
    public function prospective_agent_can_be_recruited_online()
    {
        Notification::fake();
        $voucher = RegisterOrganization::run(
            User::factory()->create(),
            $orgName = $this->faker->company(),
            ChannelEnum::random(),
            FormatEnum::random(),
            $this->faker->url(),
            $this->faker->text(20),
            Package::factory()->create()
        );
        $response = $this->withoutMiddleware([VerifyCsrfToken::class])
            ->postJson("/recruit/{$voucher->code}", [
            'name' => $this->faker->name(),
            'email' => $email = $this->faker->email(),
            'mobile' => $this->faker->mobileNumber(),
            'password' => 'password', 'password_confirmation' => 'password', 'terms' => true,
        ],  ['Accept' => 'application/json']);
        $response->assertRedirect('/dashboard');

        $agent = app(User::class)->where('email', $email)->first();
        Notification::assertSentTo($agent, SendAgentOnboardingNotification::class, function ($notification) use ($voucher) {
            return $voucher->is($notification->voucher);
        });
        $this->assertTrue(
            app(OrganizationUser::class)
            ->whereHas('organization', function ($q) use ($orgName, $agent) {
                $q->where('name', $orgName);})
            ->whereHas('user', function ($q) use ($agent) {
                $q->where('email', $agent->email);})
            ->exists()
        );
    }

    /** @test */
    public function coast_to_coast()
    {
        Notification::fake();

        /*** new enterprise user ***/
        $this->withoutMiddleware([VerifyCsrfToken::class])
            ->postJson('/register', $user_attribs = $this->fakeUserAttributes())
            ->assertSuccessful();

        /*** new registration campaign ***/
        $code = $this->postJson("/api/register-organization",
            $campaign_attribs = $this->fakeCampaignAttributes(),
            ['Authorization' => User::authorizationFromMobile($user_attribs['mobile'], $enterprise_user)])
            ->assertSuccessful()
            ->json('code');

        Notification::assertSentTo($enterprise_user, SendRegisterUserNotification::class, function (SendRegisterUserNotification $notification) use ($enterprise_user, $campaign_attribs, $code) {
            $org = Arr::get($campaign_attribs, 'name');
            $url = route('create-recruit', ['voucher' => $code]);

            return $notification->getContent($enterprise_user) == trans('domain.org-campaign', ['org' => $org, 'url' => $url]);
        });

        /*** new agent ***/
        $this->withoutMiddleware([VerifyCsrfToken::class])
            ->postJson("/recruit/{$code}", $agent_attribs = $this->fakeUserAttributes())
            ->assertRedirect('/dashboard');
    }
}
