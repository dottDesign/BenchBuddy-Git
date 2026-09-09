<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/vendor/autoload.php';

$logFile = __DIR__ . '/stripe_webhook_log.txt';

function stripe_webhook_log(string $message): void
{
    global $logFile;

    file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n",
        FILE_APPEND
    );
}

function stripe_metadata_value(object $object, string $key, string $default = ''): string
{
    if (!empty($object->metadata) && !empty($object->metadata->{$key})) {
        return (string)$object->metadata->{$key};
    }

    return $default;
}

function find_team_id_from_subscription_object(object $object): int
{
    $teamId = stripe_metadata_value($object, 'team_id', '');

    return $teamId !== '' ? (int)$teamId : 0;
}

function find_head_coach_user_id_for_team(int $teamId): int
{
    if ($teamId <= 0) {
        return 0;
    }

    $stmt = db()->prepare("
        SELECT user_id
        FROM team_memberships
        WHERE team_id = :team_id
        ORDER BY
            CASE WHEN role = 'head_coach' THEN 0 ELSE 1 END,
            user_id ASC
        LIMIT 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    return (int)$stmt->fetchColumn();
}

function get_head_coach_for_team(int $teamId): ?array
{
    if ($teamId <= 0) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT
            u.id,
            u.full_name,
            u.email,
            t.name AS team_name
        FROM team_memberships tm
        INNER JOIN users u
            ON u.id = tm.user_id
        INNER JOIN teams t
            ON t.id = tm.team_id
        WHERE tm.team_id = :team_id
          AND tm.role = 'head_coach'
        ORDER BY u.id ASC
        LIMIT 1
    ");

    $stmt->execute([
        'team_id' => $teamId,
    ]);

    $coach = $stmt->fetch(PDO::FETCH_ASSOC);

    return $coach ?: null;
}

function maybe_create_referral_reward_for_team(int $teamId, string $planKey, string $status): void
{
    if ($teamId <= 0) {
        return;
    }

    if ($planKey === 'free') {
        return;
    }

    if (!in_array($status, ['active', 'trialing'], true)) {
        return;
    }

    if (!function_exists('create_referral_rewards_for_paid_user')) {
        return;
    }

    $userId = find_head_coach_user_id_for_team($teamId);

    if ($userId <= 0) {
        return;
    }

    create_referral_rewards_for_paid_user($userId);

    stripe_webhook_log(
        'Referral reward check completed team_id=' . $teamId .
        ' user_id=' . $userId .
        ' plan_key=' . $planKey .
        ' status=' . $status
    );
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sigHeader,
        STRIPE_WEBHOOK_SECRET
    );
} catch (Throwable $e) {
    stripe_webhook_log('Invalid webhook: ' . $e->getMessage());
    http_response_code(400);
    exit('Invalid webhook');
}

stripe_webhook_log('Received event: ' . $event->type);

try {
    switch ($event->type) {
        case 'checkout.session.completed':
            $session = $event->data->object;

            $teamId = stripe_metadata_value($session, 'team_id', '') !== ''
                ? (int)stripe_metadata_value($session, 'team_id', '')
                : 0;

            $planKey = stripe_metadata_value($session, 'plan_key', 'free');
            $billingInterval = stripe_metadata_value($session, 'billing_interval', 'monthly');

            stripe_webhook_log(
                'checkout.session.completed team_id=' . $teamId .
                ' plan_key=' . $planKey .
                ' billing_interval=' . $billingInterval .
                ' customer=' . (string)($session->customer ?? '') .
                ' subscription=' . (string)($session->subscription ?? '') .
                ' payment_status=' . (string)($session->payment_status ?? '')
            );

            if ($teamId > 0) {
                update_team_subscription_from_stripe(
                    $teamId,
                    $planKey,
                    $billingInterval,
                    (string)($session->customer ?? ''),
                    (string)($session->subscription ?? ''),
                    'active',
                    null
                );

                maybe_create_referral_reward_for_team($teamId, $planKey, 'active');

                $coach = get_head_coach_for_team($teamId);

                if ($coach) {
                    $planName = match (normalize_plan_key($planKey)) {
                        'coach' => 'Coach',
                        'coachplus' => 'Coach Plus',
                        'unlimited' => 'Unlimited',
                        default => 'BenchBuddy',
                    };

                    try {
                        stripe_webhook_log(
                            'About to send subscription activated email to ' .
                            (string)$coach['email']
                        );

                        send_subscription_activated_email(
                            (string)$coach['email'],
                            (string)$coach['full_name'],
                            (string)$coach['team_name'],
                            $planName
                        );

                        stripe_webhook_log(
                            'Subscription activated email sent to ' .
                            (string)$coach['email']
                        );
                    } catch (Throwable $e) {
                        stripe_webhook_log(
                            'Subscription activated email ERROR: ' .
                            $e->getMessage()
                        );
                    }
                }

                stripe_webhook_log('Updated team from checkout.session.completed team_id=' . $teamId);
            } else {
                stripe_webhook_log('Skipped checkout.session.completed because team_id was missing.');
            }

            break;

        case 'customer.subscription.created':
            $subscription = $event->data->object;

            $teamId = find_team_id_from_subscription_object($subscription);

            $planKey = stripe_metadata_value($subscription, 'plan_key', 'free');
            $billingInterval = stripe_metadata_value($subscription, 'billing_interval', 'monthly');
            $status = (string)($subscription->status ?? 'active');

            stripe_webhook_log(
                'customer.subscription.created team_id=' . $teamId .
                ' plan_key=' . $planKey .
                ' billing_interval=' . $billingInterval .
                ' status=' . $status .
                ' customer=' . (string)($subscription->customer ?? '') .
                ' subscription=' . (string)($subscription->id ?? '')
            );

            if ($teamId > 0) {
                update_team_subscription_from_stripe(
                    $teamId,
                    $planKey,
                    $billingInterval,
                    (string)($subscription->customer ?? ''),
                    (string)($subscription->id ?? ''),
                    $status,
                    isset($subscription->current_period_end)
                        ? (int)$subscription->current_period_end
                        : null
                );

                maybe_create_referral_reward_for_team($teamId, $planKey, $status);

                stripe_webhook_log('Updated team from customer.subscription.created team_id=' . $teamId);
            } else {
                stripe_webhook_log('Skipped customer.subscription.created because team_id was missing.');
            }

            break;

        case 'customer.subscription.updated':
            $subscription = $event->data->object;

            $teamId = find_team_id_from_subscription_object($subscription);

            $planKey = stripe_metadata_value($subscription, 'plan_key', 'free');
            $billingInterval = stripe_metadata_value($subscription, 'billing_interval', 'monthly');
            $status = (string)($subscription->status ?? 'unknown');

            stripe_webhook_log(
                'customer.subscription.updated team_id=' . $teamId .
                ' plan_key=' . $planKey .
                ' billing_interval=' . $billingInterval .
                ' status=' . $status .
                ' customer=' . (string)($subscription->customer ?? '') .
                ' subscription=' . (string)($subscription->id ?? '')
            );

            if ($teamId > 0) {
                update_team_subscription_from_stripe(
                    $teamId,
                    $planKey,
                    $billingInterval,
                    (string)($subscription->customer ?? ''),
                    (string)($subscription->id ?? ''),
                    $status,
                    isset($subscription->current_period_end)
                        ? (int)$subscription->current_period_end
                        : null
                );

                maybe_create_referral_reward_for_team($teamId, $planKey, $status);

                stripe_webhook_log('Updated team from customer.subscription.updated team_id=' . $teamId);
            } else {
                stripe_webhook_log('Skipped customer.subscription.updated because team_id was missing.');
            }

            break;

        case 'customer.subscription.deleted':
            $subscription = $event->data->object;

            $teamId = find_team_id_from_subscription_object($subscription);

            stripe_webhook_log(
                'customer.subscription.deleted team_id=' . $teamId .
                ' customer=' . (string)($subscription->customer ?? '') .
                ' subscription=' . (string)($subscription->id ?? '')
            );

            if ($teamId > 0) {
                update_team_subscription_from_stripe(
                    $teamId,
                    'free',
                    'free',
                    (string)($subscription->customer ?? ''),
                    (string)($subscription->id ?? ''),
                    'canceled',
                    isset($subscription->current_period_end)
                        ? (int)$subscription->current_period_end
                        : null
                );

                $coach = get_head_coach_for_team($teamId);

                if ($coach) {
                    try {
                        stripe_webhook_log(
                            'About to send subscription cancelled email to ' .
                            (string)$coach['email']
                        );

                        send_subscription_cancelled_email(
                            (string)$coach['email'],
                            (string)$coach['full_name'],
                            (string)$coach['team_name']
                        );

                        stripe_webhook_log(
                            'Subscription cancelled email sent to ' .
                            (string)$coach['email']
                        );
                    } catch (Throwable $e) {
                        stripe_webhook_log(
                            'Subscription cancelled email ERROR: ' .
                            $e->getMessage()
                        );
                    }
                }

                stripe_webhook_log('Downgraded team from customer.subscription.deleted team_id=' . $teamId);
            } else {
                stripe_webhook_log('Skipped customer.subscription.deleted because team_id was missing.');
            }

            break;

        case 'invoice.payment_failed':
            $invoice = $event->data->object;

            stripe_webhook_log(
                'invoice.payment_failed customer=' . (string)($invoice->customer ?? '') .
                ' subscription=' . (string)($invoice->subscription ?? '')
            );

            $subscriptionId = (string)($invoice->subscription ?? '');

            if ($subscriptionId !== '') {
                $stmt = db()->prepare("
                    SELECT id
                    FROM teams
                    WHERE stripe_subscription_id = :subscription_id
                    LIMIT 1
                ");

                $stmt->execute([
                    'subscription_id' => $subscriptionId,
                ]);

                $teamId = (int)$stmt->fetchColumn();

                if ($teamId > 0) {
                    $coach = get_head_coach_for_team($teamId);

                    if ($coach) {
                        try {
                            stripe_webhook_log(
                                'About to send payment failed email to ' .
                                (string)$coach['email']
                            );

                            send_payment_failed_email(
                                (string)$coach['email'],
                                (string)$coach['full_name'],
                                (string)$coach['team_name']
                            );

                            stripe_webhook_log(
                                'Payment failed email sent to ' .
                                (string)$coach['email']
                            );
                        } catch (Throwable $e) {
                            stripe_webhook_log(
                                'Payment failed email ERROR: ' .
                                $e->getMessage()
                            );
                        }
                    }
                } else {
                    stripe_webhook_log('invoice.payment_failed could not find team for subscription=' . $subscriptionId);
                }
            } else {
                stripe_webhook_log('invoice.payment_failed missing subscription id.');
            }

            break;

        default:
            stripe_webhook_log('Ignored event: ' . $event->type);
            break;
    }
} catch (Throwable $e) {
    stripe_webhook_log('Webhook processing failed: ' . $e->getMessage());

    http_response_code(500);
    exit('Webhook processing failed');
}

http_response_code(200);
echo 'ok';
