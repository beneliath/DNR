<footer>
    <p style="opacity: 0.2; display: inline;">&copy; <?php echo date("Y"); ?> beneliath</p>
    &nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;
    <a href="calendar_subscription.php" style="text-decoration: none;">Calendar</a>
    &nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;
    <form method="post" action="logout.php" id="logout-form" style="display: inline;">
        <?php echo csrfInput(); ?>
        <button type="submit" class="logout-link-button" style="border: 0; padding: 0; background: none; color: var(--link-color); font: inherit; cursor: pointer;">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</button>
    </form>

    <dialog id="logout-confirmation" class="logout-confirmation" aria-labelledby="logout-confirmation-title">
        <h2 id="logout-confirmation-title">Confirm logout</h2>
        <p>Are you sure you want to log out?</p>
        <div class="logout-confirmation-actions">
            <button type="button" id="cancel-logout">Cancel</button>
            <button type="button" id="confirm-logout" class="danger-button">Log out</button>
        </div>
    </dialog>
    
    <!-- ASCII Art Container -->
    <div class="ascii-art-container" style="opacity: 0.2; font-size: 1.0em;">
    <pre style="font-size: 0.8em;">
     ("`-''-/").___..--''"`-.
     `6_ 6  )   `-.  (     ).`-.__.`)
     (_Y_.)'  ._   )  `._ `. ``-..-'
   _..`--'_..-_/  /--'_.' ,'                repo:  https://github.com/beneliath/DNR
  (il),-''  (li),'  ((!.-'                 title:  DNR - deploy & report
                                         version:  0.0.2
Genesis 49:9,10 ... Revelation 5:5     timestamp:  2026-08-14 08:12:32
         Do you see Him?
    </pre>
    </div>
</footer>
<script>
(function () {
    const logoutForm = document.getElementById('logout-form');
    const confirmation = document.getElementById('logout-confirmation');
    const cancelButton = document.getElementById('cancel-logout');
    const confirmButton = document.getElementById('confirm-logout');
    let logoutConfirmed = false;

    logoutForm.addEventListener('submit', function (event) {
        if (!logoutConfirmed) {
            event.preventDefault();
            confirmation.showModal();
        }
    });

    cancelButton.addEventListener('click', function () {
        confirmation.close();
    });

    confirmButton.addEventListener('click', function () {
        logoutConfirmed = true;
        logoutForm.requestSubmit();
    });
})();
</script>
