<?php
declare(strict_types=1);

http_response_code(404);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = '404 | Page Not Found';
$currentPage = '404';

require_once __DIR__ . '/includes/header.php';
?>

<section class="bb-404">

<div class="bb-404-title">
  <div class="bb-404-card">
    <div class="bb-404-number">
      <span>4</span>

      <div class="bb-404-baseball">
        <div class="baseball-texture"></div>
      </div>

    <span>4</span>
    </div>

    <p class="bb-eyebrow">Page Not Found</p>

    <h1 class="brand-title-font">Looks like this page missed the cutoff.</h1>

    <p class="muted">
      The URL you entered does not match an active BenchBuddy page.
      Head back to the dugout and keep building your lineup.
    </p>
  </div>
</div>
    <div class="bb-404-stats">
      <div>
        <strong>0</strong>
        <span>Pages Found</span>
      </div>
      <div>
        <strong>1</strong>
        <span>Coach Redirected</span>
      </div>
      <div>
        <strong>100%</strong>
        <span>Recoverable</span>
      </div>
    </div>

    <div class="actions-row">
      <a class="btn" href="index.php">Back to Dashboard</a>
      <a class="btn btn-secondary" href="games.php">Go to Games</a>
      <a class="btn btn-secondary" href="sitemap.php">View Sitemap</a>
    </div>
  </div>
</section>

<style>.bb-404 {

  max-width: 980px;
  margin: 40px auto;
  padding: 24px;

}
.bb-404-title{margin: 0 auto;text-align: center;}

.bb-404-card {
  padding: 38px;
  border-radius: 30px;
  background:
    radial-gradient(circle at top right, rgba(255,255,255,.9), transparent 34%),
    linear-gradient(135deg, #ffffff, #f8fafc);
  border: 1px solid #e5e7eb;
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
}

.bb-404-number {
    font-size: clamp(86px, 15vw, 170px);
        font-weight: 950;
        line-height: .9;
        color: var(--primary);
        letter-spacing: -0.08em;
        margin: 0 auto;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.08em;
}

.bb-404-card h1 {
  max-width: 680px;
  margin: 0 auto;
  font-size: clamp(34px, 5vw, 58px);
  letter-spacing: -0.05em;
}

.bb-404-card .muted {
  max-width: 620px;
  font-size: 17px;
  line-height: 1.6;
  margin: 0 auto;
}

.bb-404-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  margin: 24px 0;
}

.bb-404-stats div {
  padding: 16px;
  border-radius: 18px;
  background: #f8fafc;
  border: 1px solid #e5e7eb;
}

.bb-404-stats strong {
  display: block;
  font-size: 28px;
  color: var(--primary);
}

.bb-404-stats span {
  color: #64748b;
  font-size: 13px;
  font-weight: 700;
}

@media (max-width: 700px) {
  .bb-404-scoreboard,
  .bb-404-count {
    flex-direction: column;
  }

  .bb-404-card {
    padding: 26px;
  }

  .bb-404-stats {
    grid-template-columns: 1fr;
  }
}



.bb-404-baseball {
  position: relative;
  width: 0.82em;
  height: 0.82em;
  flex-shrink: 0;
  border-radius: 50%;
}

.bb-404-baseball .baseball-texture {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background-image: url("/assets/images/baseball_texture.jpg");
  background-size: cover;
  background-repeat: no-repeat;
  background-position: center;
  box-shadow:
    inset -0.04em -0.04em 0.08em rgba(0,0,0,.14),
    0 0.04em 0.1em rgba(0,0,0,.24);
    animation-name: spin;
      animation-duration: 5000ms;
      animation-iteration-count: infinite;
      animation-timing-function: linear;
      /* transform: rotate(3deg); */
       /* transform: rotate(0.3rad);/ */
       /* transform: rotate(3grad); */
       /* transform: rotate(.03turn);  */

    }


    @keyframes spin {
        from {
            transform:rotate(0deg);
        }
        to {
            transform:rotate(360deg);
        }
    }
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
