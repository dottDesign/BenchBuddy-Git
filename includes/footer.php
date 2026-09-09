<?php
declare(strict_types=1);
?>
</main>
<?php
if (function_exists('is_logged_in') && is_logged_in()) {
    require_once __DIR__ . '/coach_helper_widget.php';
}
?>
<div class="grass-divider"></div>
<footer class="site-footer no-print">
  <div class="footer-inner footer-large">

    <div class="footer-hero">
      <div class="footer-brand-panel">
        <div class="footer-logo-mark footer-logo-image">
          <img src="/assets/apple-touch-icon.png" alt="BenchBuddy" loading="lazy">
        </div>

        <div>
          <div class="footer-kicker">BenchBuddy · Beta release</div>
          <h2>Coach smarter. Build better lineups.</h2>
          <p>
            The Dugout’s Smartest Clipboard. Manage teams, build lineups,
            track pitch counts, organize game history, and print clean game day sheets.
          </p>
        </div>
      </div>

      <div class="footer-hero-actions">
        <?php if (is_logged_in()): ?>
          <a class="btn" href="games.php">Create Game</a>
          <a class="btn btn-secondary" href="feature_request_form.php">Request Feature</a>
        <?php else: ?>
          <a class="btn" href="signup.php">Get Started</a>
          <a class="btn btn-secondary" href="login.php">Log In</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="footer-main-grid">
      <?php if (is_logged_in()): ?>
        <div class="footer-column">
          <h3>Team Setup</h3>
          <a href="team_settings.php">Team Settings</a>
          <a href="players.php">Add/Edit Players</a>
          <a href="player_profiles.php">Player Profiles</a>
          <a href="lineup_templates.php">Lineup Templates</a>
        </div>

        <div class="footer-column">
          <h3>Game Day</h3>
          <a href="games.php">Create Games</a>
          <a href="generate.php">Build Lineup</a>
          <a href="lock.php">Lock Lineup</a>
          <a href="print_blank_lineup.php">Blank Lineup Sheet</a>
        </div>

        <div class="footer-column">
          <h3>Review</h3>
          <a href="history.php">Game History</a>
          <a href="cancelled_games.php">Cancelled Games</a>
          <a href="bulk_pitch_counts.php">Add Pitch Counts</a>
          <a href="pitch_rules.php">Pitch Rules</a>
        </div>

        <div class="footer-column">
          <h3>Account</h3>
          <a href="account.php">Account Settings</a>
          <?php if (function_exists('billing_enabled') && billing_enabled()): ?>
            <a href="billing.php">Billing</a>
          <?php endif; ?>
          <a href="features.php">What’s New</a>
          <a href="logout.php">Logout</a>
        </div>
      <?php else: ?>
        <div class="footer-column">
          <h3>Get Started</h3>
          <a href="signup.php">Create Account</a>
          <a href="login.php">Log In</a>
          <a href="features.php">What’s New</a>
        </div>

        <div class="footer-column">
          <h3>Platform</h3>
          <a href="signup.php">Lineup Builder</a>
          <a href="signup.php">Pitch Tracking</a>
          <a href="signup.php">Player Management</a>
          <a href="signup.php">Printable Sheets</a>
        </div>
      <?php endif; ?>

      <div class="footer-column">
        <h3>Resources</h3>
        <a href="https://www.bcminorbaseball.org/rulesbooks" target="_blank" rel="noopener">
          BC Minor Rules
        </a>
        <a href="mailto:benchbuddy.devworks@gmail.com">Contact Support</a>
        <a href="https://wa.me/17788781422" target="_blank" rel="noopener">Report a Bug</a>
        <a href="https://buymeacoffee.com/dottenbreit" target="_blank" rel="noopener">
          Buy the Coach a Coffee
        </a>
        <a href="sitemap.php">Sitemap</a>

      </div>

      <div class="footer-column">
        <h3>Legal</h3>
        <a href="privacy_policy.php">Privacy Policy</a>
        <a href="terms.php">Terms of Service</a>
        <a href="cookie_policy.php">Cookie Policy</a>
      </div>
    </div>

    <div class="footer-learn-strip">
      <div>
        <strong>Built for coaches.</strong>
        <span>Fast setup, clean printouts, and practical tools for real game days.</span>
      </div>

      <div class="footer-learn-links">
        <a href="features.php">See updates</a>
        <a href="mailto:benchbuddy.devworks@gmail.com">Contact</a>
      </div>
    </div>


    <div class="bb-footer-quote" aria-live="polite">
        <blockquote id="bbBaseballQuote">
            “Baseball is ninety percent mental. The other half is physical.”
        </blockquote>
        <div id="bbBaseballQuoteAuthor">
            — Yogi Berra
        </div>

    </div>

    <div class="footer-bottom">
      <span>© <?= date('Y') ?> BenchBuddy. All rights reserved.</span>
      <span>Built by <strong>DevWorks</strong></span>
      <span>Beta release</span>
    </div>

  </div>
</footer>
<div class="cookie-banner" id="cookieBanner">
  <div class="cookie-banner-inner">
    <div class="cookie-text">
      This site uses essential cookies to keep you logged in and improve the experience.
      See our <a href="cookie_policy.php">Cookie Policy</a>.
    </div>

    <div class="cookie-actions">
      <button class="btn btn-secondary" id="cookieDecline">Decline</button>
      <button class="btn" id="cookieAccept">Accept</button>
    </div>
  </div>
</div>
<script>
(function () {
  const banner = document.getElementById('cookieBanner');
  const acceptBtn = document.getElementById('cookieAccept');
  const declineBtn = document.getElementById('cookieDecline');
  const COOKIE_NAME = "lineup_cookie_consent";
  function setCookie(name, value, days) {
    const date = new Date();
    date.setTime(date.getTime() + (days*24*60*60*1000));
    document.cookie = name + "=" + value + ";expires=" + date.toUTCString() + ";path=/";
  }
  function getCookie(name) {
    const value = "; " + document.cookie;
    const parts = value.split("; " + name + "=");
    if (parts.length === 2) return parts.pop().split(";").shift();
  }
  function hideBanner() {
    banner.style.display = "none";
  }
  if (!getCookie(COOKIE_NAME)) {
    banner.style.display = "block";
  }
  acceptBtn.addEventListener("click", function() {
    setCookie(COOKIE_NAME, "accepted", 365);
    hideBanner();
  });
  declineBtn.addEventListener("click", function() {
    setCookie(COOKIE_NAME, "declined", 365);
    hideBanner();
  });
})();
</script>

<script>
(function () {
  const btn = document.getElementById('coffeeSupportButton');
  if (!btn) {
    return;
  }
  const hasBuiltLineup = localStorage.getItem('benchbuddy_first_lineup_built') === '1';
  if (hasBuiltLineup) {
    btn.style.display = 'flex';
  }
})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const swapToggle = document.getElementById('swap_mode_toggle');
  const selects = document.querySelectorAll('.lineup-select');

  function getInningSelects(inning, changedSelect) {
    return Array.from(document.querySelectorAll('.lineup-select')).filter(function (select) {
      return select.dataset.inning === String(inning) && select !== changedSelect;
    });
  }
  selects.forEach(function (select) {
    select.addEventListener('focus', function () {
      this.dataset.previousValue = this.value;
    });

    if (!select.dataset.previousValue) {
      select.dataset.previousValue = select.value;
    }
    select.addEventListener('change', function () {
      if (!swapToggle || !swapToggle.checked) {
        this.dataset.previousValue = this.value;
        return;
      }
      const newValue = this.value;
      const oldValue = this.dataset.previousValue || '';
      const inning = this.dataset.inning;
      if (!newValue || !inning) {
        this.dataset.previousValue = this.value;
        return;
      }
      const inningSelects = getInningSelects(inning, this);

      let duplicateSelect = null;
      for (const other of inningSelects) {
        if (other.value === newValue) {
          duplicateSelect = other;
          break;
        }
      }
      if (duplicateSelect) {
        duplicateSelect.value = oldValue;
        duplicateSelect.dataset.previousValue = oldValue;
      }
      this.dataset.previousValue = this.value;
    });
  });
});
</script>
<?php if (function_exists('is_logged_in') && is_logged_in()): ?>
<div id="session-timeout-modal" class="help-modal-backdrop hide">
  <div class="help-modal">
    <div class="help-modal-header">
      <h2>Session Timeout Warning</h2>
    </div>

    <div class="help-modal-body">
      <div class="help-step">
        <strong>You have been inactive for a while.</strong>
        <p>Your session may expire soon. Save any changes before continuing.</p>
      </div>
    </div>

    <div class="help-modal-footer">
      <button type="button" class="btn btn-secondary" id="session-timeout-stay">
        Keep Working
      </button>
      <a class="btn" href="logout.php">Log Out</a>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.getElementById('session-timeout-modal');
  const stayButton = document.getElementById('session-timeout-stay');

  if (!modal || !stayButton) return;

  let warningTimer = null;
  let logoutTimer = null;

  function redirectToLogin() {
    window.location.href = '/login.php?expired=1';
  }

  function hideWarning() {
    modal.classList.add('hide');
    modal.style.display = 'none';
    document.body.classList.remove('modal-open');
  }

  function showWarning(secondsRemaining) {
    modal.classList.remove('hide');
    modal.style.display = 'flex';
    document.body.classList.add('modal-open');

    clearTimeout(logoutTimer);
    logoutTimer = setTimeout(redirectToLogin, Math.max(1, secondsRemaining) * 1000);
  }

  function scheduleWarning(secondsRemaining, warningSeconds) {
    clearTimeout(warningTimer);
    clearTimeout(logoutTimer);

    const warningDelay = Math.max(0, secondsRemaining - warningSeconds) * 1000;

    warningTimer = setTimeout(function () {
      showWarning(warningSeconds);
    }, warningDelay);
  }

  async function syncSessionTimer() {
    try {
      const response = await fetch('/session_status_check.php', {
        credentials: 'same-origin'
      });

      const data = await response.json();

      if (!data.logged_in || data.seconds_remaining <= 0) {
        redirectToLogin();
        return;
      }

      scheduleWarning(
        Number(data.seconds_remaining),
        Number(data.warning_seconds)
      );
    } catch (e) {}
  }

  stayButton.addEventListener('click', async function () {
    try {
      const response = await fetch('/session_keepalive.php', {
        method: 'POST',
        credentials: 'same-origin'
      });

      if (response.status === 401 || response.status === 403) {
        redirectToLogin();
        return;
      }

      const data = await response.json();

      hideWarning();

      scheduleWarning(
        Number(data.seconds_remaining),
        Number(data.warning_seconds)
      );
    } catch (e) {
      syncSessionTimer();
    }
  });

  syncSessionTimer();
});
</script>

<script>
function openFeatureRequestModal(button) {
  const modal = document.getElementById('featureRequestModal');
  const title = document.getElementById('featureRequestModalTitle');
  const meta = document.getElementById('featureRequestModalMeta');
  const description = document.getElementById('featureRequestModalDescription');
  const notesWrap = document.getElementById('featureRequestModalNotesWrap');
  const notes = document.getElementById('featureRequestModalNotes');

  if (!modal) return;

  title.textContent = button.dataset.title || 'Feature Request';
  meta.textContent = (button.dataset.status || 'Status') + ' · Submitted ' + (button.dataset.date || '');
  description.textContent = button.dataset.description || '';

  if (button.dataset.notes) {
    notes.textContent = button.dataset.notes;
    notesWrap.classList.remove('hide');
  } else {
    notes.textContent = '';
    notesWrap.classList.add('hide');
  }

  modal.classList.remove('hide');
  modal.style.display = 'flex';
  document.body.classList.add('modal-open');
}

function closeFeatureRequestModal() {
  const modal = document.getElementById('featureRequestModal');

  if (!modal) return;

  modal.classList.add('hide');
  modal.style.display = 'none';
  document.body.classList.remove('modal-open');
}

document.addEventListener('click', function (event) {
  const modal = document.getElementById('featureRequestModal');

  if (modal && event.target === modal) {
    closeFeatureRequestModal();
  }
});

document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape') {
    closeFeatureRequestModal();
  }
});
</script>
<?php endif; ?>
<script src="https://unpkg.com/lucide@latest"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    lucide.createIcons();
});
</script>


<?php if (!empty($_SESSION['next_step_modal'])): ?>
    <?php
        $nextStepModal = $_SESSION['next_step_modal'];
        unset($_SESSION['next_step_modal']);
    ?>

    <div id="nextStepModal" class="help-modal-backdrop" style="display:flex;">
        <div class="help-modal">
            <div class="help-modal-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <h2 style="margin:0;"><?= h((string)$nextStepModal['title']) ?></h2>

                <button
                    type="button"
                    class="btn btn-secondary"
                    style="padding:6px 10px;line-height:1;"
                    onclick="document.getElementById('nextStepModal').style.display='none';"
                    aria-label="Close"
                >
                    Close
                </button>
            </div>

            <div class="help-modal-body">
                <div class="help-step">
                    <p><?= h((string)$nextStepModal['message']) ?></p>
                </div>
            </div>

            <div class="help-modal-footer">
                <?php if (!empty($nextStepModal['secondary_text']) && !empty($nextStepModal['secondary_url'])): ?>
                    <a class="btn btn-secondary" href="<?= h((string)$nextStepModal['secondary_url']) ?>">
                        <?= h((string)$nextStepModal['secondary_text']) ?>
                    </a>
                <?php endif; ?>

                <a class="btn" href="<?= h((string)$nextStepModal['primary_url']) ?>">
                    <?= h((string)$nextStepModal['primary_text']) ?>
                </a>

                <form method="post" action="dismiss_onboarding.php" style="width:100%;margin-top:12px;text-align:center;">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-secondary">
                        Don’t show onboarding tips again
                    </button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const quoteElement = document.getElementById('bbBaseballQuote');
    const authorElement = document.getElementById('bbBaseballQuoteAuthor');

    if (!quoteElement || !authorElement) {
        return;
    }

    const quotes = [
        {
            quote: 'Today I consider myself the luckiest man on the face of the earth.',
            author: 'Lou Gehrig',
            context: 'Yankee Stadium, July 4, 1939'
        },
        {
            quote: 'A life is not important except in the impact it has on other lives.',
            author: 'Jackie Robinson'
        },
        {
            quote: 'Never let the fear of striking out keep you from playing the game.',
            author: 'Babe Ruth'
        },
        {
            quote: 'Every strike brings me closer to the next home run.',
            author: 'Babe Ruth'
        },
        {
            quote: 'Baseball is the only field of endeavor where a man can succeed three times out of ten and be considered a good performer.',
            author: 'Ted Williams'
        },
        {
            quote: 'It ain’t over till it’s over.',
            author: 'Yogi Berra'
        },
        {
            quote: 'Don’t look back. Something might be gaining on you.',
            author: 'Satchel Paige'
        },
        {
            quote: 'I am convinced that God wanted me to be a baseball player.',
            author: 'Roberto Clemente'
        },
        {
            quote: 'You may not think you’re going to make it. You may want to quit. But if you keep your eye on the ball, you can accomplish anything.',
            author: 'Hank Aaron'
        },
        {
            quote: 'It’s not what you achieve, it’s what you overcome. That’s what defines your career.',
            author: 'Carlton Fisk'
        },
        {
            quote: 'Confidence is how you go about your business. Cocky is when you have to explain it to somebody.',
            author: 'Ken Griffey Jr.'
        },
        {
            quote: 'Never allow the circumstances of your life to become an excuse.',
            author: 'Jim Abbott'
        },
        {
            quote: 'I was told I would never make it because I’m too short. Well, I’m still too short, but I’ve got 10 All-Star games, two World Series championships, and I’m a very happy and contented guy. It doesn’t matter what your height is, it’s what’s in your heart.',
            author: 'Kirby Puckett'
        },
        {
            quote: 'Why does everyone talk about the past? All that counts is tomorrow’s game.',
            author: 'Roberto Clemente'
        },
        {
            quote: 'The best pitchers have a short-term memory and a bulletproof confidence.',
            author: 'Greg Maddux'
        },
        {
            quote: 'It’s hard to beat a person who never gives up.',
            author: 'Babe Ruth'
        },
        {
            quote: 'If my uniform doesn’t get dirty, I haven’t done anything in the baseball game.',
            author: 'Rickey Henderson'
        },
        {
            quote: 'There is no time to fool around when you practice. Every drill must have a purpose.',
            author: 'Albert Pujols'
        },
        {
            quote: 'Pressure is a word that is misused in our vocabulary. When you start thinking of pressure, it’s because you’ve started to think of failure.',
            author: 'Tommy Lasorda'
        },
        {
            quote: 'Hustle is just playing the game right.',
            author: 'Jimmy Rollins'
        },
        {
            quote: 'Set your goals high, and don’t stop till you get there.',
            author: 'Bo Jackson'
        },
        {
            quote: 'I’d walk through hell in a gasoline suit to play baseball.',
            author: 'Pete Rose'
        },
        {
            quote: 'Play this game like the 8-year-old you used to be, dreaming to play in the show! Heart, passion, and fire! Remember where you came from!',
            author: 'Bryce Harper'
        },
        {
            quote: 'If you’re not practicing, somebody else is, somewhere, and he’ll be ready to take your job.',
            author: 'Brooks Robinson'
        },
        {
            quote: 'There is always some kid who may be seeing me for the first time. I owe him my best.',
            author: 'Joe DiMaggio'
        },
        {
            quote: 'Show me a guy who’s afraid to look bad, and I’ll show you a guy you can beat every time.',
            author: 'Lou Brock'
        },
        {
            quote: 'There may be people who have more talent than you, but there’s no excuse for anyone to work harder than you do.',
            author: 'Derek Jeter'
        },
        {
            quote: 'Enjoy your sweat because hard work doesn’t guarantee success, but without it you don’t have a chance.',
            author: 'Alex Rodriguez'
        },
        {
            quote: 'Baseball is such a tough game, it really humbles you at times. You just have to try not to get too high or too low.',
            author: 'Chase Utley'
        },
        {
            quote: 'It’s unbelievable how much you don’t know about the game you’ve been playing your whole life.',
            author: 'Mickey Mantle'
        },
        {
            quote: 'Remember these two things: play hard and have fun.',
            author: 'Tony Gwynn'
        },
        {
            quote: 'You could be a kid for as long as you want when you play baseball.',
            author: 'Cal Ripken Jr.'
        },
        {
            quote: 'I never had a job. I just always played baseball.',
            author: 'Satchel Paige'
        },
        {
            quote: 'There is no room in baseball for discrimination. It is our national pastime and a game for all.',
            author: 'Lou Gehrig'
        },
        {
            quote: 'Any time you have an opportunity to make a difference in this world and you don’t, then you are wasting your time on Earth.',
            author: 'Roberto Clemente'
        },
        {
            quote: 'The key to winning baseball games is pitching, fundamentals, and three-run homers.',
            author: 'Earl Weaver'
        },
        {
            quote: 'Baseball is like church. Many attend, but few understand.',
            author: 'Leo Durocher'
        },
        {
            quote: 'Close doesn’t count in baseball. Close only counts in horseshoes and grenades.',
            author: 'Frank Robinson'
        },
        {
            quote: 'There are three types of baseball players: those who make it happen, those who watch it happen, and those who wonder what happened.',
            author: 'Tommy Lasorda'
        },
        {
            quote: 'I never blame myself when I’m not hitting. I just blame the bat. And if it keeps up, I change bats.',
            author: 'Yogi Berra'
        },
        {
            quote: 'I had pretty good stuff that day. We lost.',
            author: 'Bob Uecker',
            context: 'On his playing career'
        },
        {
            quote: 'A baseball game is simply a nervous breakdown divided into nine innings.',
            author: 'Earl Wilson'
        },
        {
            quote: 'Catching a fly ball is a pleasure. Knowing what to do with it after you catch it is a business.',
            author: 'Tommy Henrich'
        },
        {
            quote: 'They give you a round bat, and they throw you a round ball, and then they tell you to hit it square.',
            author: 'Willie Stargell'
        },
        {
            quote: 'Somebody once asked me if I ever went up to the plate trying to hit a home run. I said, “Sure, every time.”',
            author: 'Mickey Mantle'
        },
        {
            quote: 'When you come to a fork in the road, take it.',
            author: 'Yogi Berra'
        },
        {
            quote: 'Baseball is ninety percent mental. The other half is physical.',
            author: 'Yogi Berra'
        },
        {
            quote: 'It’s déjà vu all over again.',
            author: 'Yogi Berra'
        },
        {
            quote: 'Nobody goes there anymore. It’s too crowded.',
            author: 'Yogi Berra'
        },
        {
            quote: 'You can observe a lot by watching.',
            author: 'Yogi Berra'
        },
        {
            quote: 'If you don’t know where you’re going, you’ll end up someplace else.',
            author: 'Yogi Berra'
        },
        {
            quote: 'We made too many wrong mistakes.',
            author: 'Yogi Berra'
        },
        {
            quote: 'The future ain’t what it used to be.',
            author: 'Yogi Berra'
        },
        {
            quote: 'Slump? I ain’t in no slump. I just ain’t hitting.',
            author: 'Yogi Berra'
        },
        {
            quote: 'Little League baseball is a very good thing because it keeps the parents off the streets.',
            author: 'Yogi Berra'
        },
        {
            quote: 'Love is the most important thing in the world, but baseball is pretty good, too.',
            author: 'Yogi Berra'
        }
    ];

    const randomIndex = Math.floor(Math.random() * quotes.length);
    const selectedQuote = quotes[randomIndex];

    quoteElement.textContent = '' + selectedQuote.quote + '';
    authorElement.textContent = '— ' + selectedQuote.author;
});
</script>
</body>
</html>
