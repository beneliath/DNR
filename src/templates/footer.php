<footer>
    <p style="opacity: 0.2; display: inline;">&copy; <?php echo date("Y"); ?> beneliath&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;</p>
    <form method="post" action="logout.php" style="display: inline;">
        <?php echo csrfInput(); ?>
        <button type="submit" class="logout-link-button" style="border: 0; padding: 0; background: none; color: var(--link-color); font: inherit; cursor: pointer;">Logout (<?php echo htmlspecialchars($_SESSION['username']); ?>)</button>
    </form>
    
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
