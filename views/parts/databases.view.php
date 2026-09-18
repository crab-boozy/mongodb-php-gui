<h2>Databases</h2>

<ul id="mpg-databases-list">
    <?php foreach ($databaseNames as $databaseName) : ?>
    <li>
        <i class="fa fa-database" aria-hidden="true"></i>
        <a class="mpg-database-link" data-database-name="<?php echo htmlspecialchars($databaseName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" href="#<?php echo htmlspecialchars($databaseName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($databaseName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>