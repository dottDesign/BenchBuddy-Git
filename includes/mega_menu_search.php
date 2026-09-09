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

    <button type="submit"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-search-icon lucide-search"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg></button>
  </form>

  <div class="mega-search-chips">
    <a href="search.php?q=top+hitters">Top Hitters</a>
    <a href="search.php?q=top+pitchers">Top Pitchers</a>
    <a href="search.php?q=recent+games">Recent Games</a>
    <a href="search.php?q=stolen+bases">SB Leaders</a>
  </div>
</div>
