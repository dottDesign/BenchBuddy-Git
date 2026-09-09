<div class="mega-menu-search-footer">
  <form action="search.php" method="get" class="mega-menu-search-form js-search-autocomplete">
      <?= csrf_field() ?>
    <div class="mega-search-field">
      <input
        type="search"
        name="q"
        placeholder="Search BenchBuddy..."
        autocomplete="off"
      >

      <div class="mega-search-suggestions" hidden></div>
    </div>

    <button type="submit">🔍</button>
  </form>

  <div class="mega-search-chips">
    <a href="search.php?q=top+hitters">Top Hitters</a>
    <a href="search.php?q=top+pitchers">Top Pitchers</a>
    <a href="search.php?q=recent+games">Recent Games</a>
    <a href="search.php?q=stolen+bases">SB Leaders</a>
  </div>
</div>
