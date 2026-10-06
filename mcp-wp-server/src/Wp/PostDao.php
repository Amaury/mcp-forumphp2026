<?php

namespace Mcp\Wp;

use PDO;

/**
 * Accès direct à la base WordPress via PDO.
 * Pas de wp-load.php ni de fonctions WordPress : le schéma de wp_posts est
 * stable depuis des années et se requête très bien en SQL nu.
 *
 * Le préfixe des tables est codé en dur (wp_), celui de notre installation
 * de démo. Sur une installation à préfixe personnalisé, adapter les requêtes.
 */
final class PostDao {
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Cherche et liste les articles, du plus récent au plus ancien.
     * Sans argument : les derniers articles (publiés et brouillons).
     * $status filtre par statut ('any' : tous), $query cherche dans les
     * titres et les contenus (LIKE naïf, suffisant pour la démo).
     */
    public function searchPosts(?string $query = null, string $status = 'any', int $limit = 20): array {
        $sql = "SELECT ID, post_title, post_status, post_date, post_modified,
                       SUBSTRING(post_content, 1, 200) AS content_start
                FROM wp_posts WHERE post_type = 'post'";
        $params = [];
        if ($status !== 'any') {
            $sql .= ' AND post_status = ?';
            $params[] = $status;
        } else {
            $sql .= " AND post_status IN ('publish', 'draft')";
        }
        if ($query !== null && $query !== '') {
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $sql .= ' AND (post_title LIKE ? OR post_content LIKE ?)';
            array_push($params, $like, $like);
        }
        $sql .= ' ORDER BY post_date DESC LIMIT ' . max(1, min(50, $limit));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Un article complet, avec ses catégories et ses tags. */
    public function getPost(int $id): array {
        $stmt = $this->pdo->prepare(
            "SELECT ID, post_author, post_title, post_name, post_status, post_date, post_modified, post_content
             FROM wp_posts WHERE ID = ? AND post_type = 'post'");
        $stmt->execute([$id]);
        if (($post = $stmt->fetch()) === false)
            throw new \DomainException("Article $id introuvable.");
        return $post + $this->terms($id);
    }

    /**
     * Met à jour le contenu (et éventuellement le statut) d'un article.
     * post_modified est rafraîchi manuellement : d'habitude c'est le code PHP
     * de WordPress qui s'en charge, pas la base. Titre et slug intacts.
     */
    public function updatePost(int $id, string $content, ?string $status = null): array {
        $this->getPost($id); // vérifie l'existence, lève une exception sinon
        $sql = "UPDATE wp_posts SET post_content = ?, post_modified = ?, post_modified_gmt = ?"
            . ($status !== null ? ', post_status = ?' : '') . ' WHERE ID = ?';
        $params = [$content, date('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')];
        if ($status !== null)
            $params[] = $status;
        $params[] = $id;
        $this->pdo->prepare($sql)->execute($params);
        return ['id' => $id, 'updated' => true, 'status' => $status ?? 'unchanged'];
    }

    /** Catégories et tags d'un article (triple jointure taxonomy de WordPress). */
    private function terms(int $postId): array {
        $stmt = $this->pdo->prepare(
            "SELECT tt.taxonomy, t.name
             FROM wp_term_relationships tr
             JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             JOIN wp_terms t ON t.term_id = tt.term_id
             WHERE tr.object_id = ?");
        $stmt->execute([$postId]);
        $terms = ['categories' => [], 'tags' => []];
        foreach ($stmt as $row)
            $terms[$row['taxonomy'] === 'category' ? 'categories' : 'tags'][] = $row['name'];
        return $terms;
    }
}

