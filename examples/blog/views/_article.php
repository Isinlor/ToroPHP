<h2><a href="<?php echo Toro::get_root_path() ?>/article/<?php echo $article['slug']; ?>"><?php echo $article['title']; ?></a></h2>
<div><?php echo Markdown($article['body']); ?></div>
<hr />