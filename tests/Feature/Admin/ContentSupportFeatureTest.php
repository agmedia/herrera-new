<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Comment\Manager as CommentManager;
use App\Livewire\Admin\Content\Faq\Form as FaqForm;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Models\Content\Support\Faq;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ContentSupportFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_faq(): void
    {
        $user = $this->makeAdminUser();

        Livewire::actingAs($user)
            ->test(FaqForm::class)
            ->set('form.code', 'faq-test-1')
            ->set('form.group_code', 'support')
            ->set('form.is_active', true)
            ->set('form.is_featured', true)
            ->set('form.sort_order', 5)
            ->set('form.locale', 'en')
            ->set('form.question', 'How do I contact support?')
            ->set('form.slug', 'how-do-i-contact-support')
            ->set('form.answer_html', '<p>Use support form.</p>')
            ->call('save')
            ->assertRedirect(route('admin.content.faqs.index', ['locale' => 'en']));

        $faq = Faq::query()->where('code', 'faq-test-1')->first();
        $this->assertNotNull($faq);
        $this->assertSame('support', $faq->group_code);
        $this->assertTrue((bool) $faq->is_featured);
        $this->assertSame(
            'How do I contact support?',
            (string) $faq->translation('en')->first()?->question
        );
    }

    public function test_admin_can_moderate_comment_status(): void
    {
        $user = $this->makeAdminUser();
        $product = $this->createProduct($user);

        $comment = Comment::query()->create([
            'commentable_type' => Product::class,
            'commentable_id' => $product->id,
            'user_id' => $user->id,
            'author_name' => 'Tester',
            'author_email' => 'tester@example.test',
            'locale' => 'en',
            'body' => 'Pending moderation.',
            'status' => Comment::STATUS_PENDING,
        ]);

        Livewire::actingAs($user)
            ->test(CommentManager::class)
            ->call('approve', $comment->id);

        $comment->refresh();
        $this->assertSame(Comment::STATUS_APPROVED, $comment->status);
        $this->assertSame($user->id, $comment->reviewed_by);
        $this->assertNotNull($comment->reviewed_at);
    }

    public function test_admin_can_edit_comment(): void
    {
        $user = $this->makeAdminUser();
        $product = $this->createProduct($user);

        $comment = Comment::query()->create([
            'commentable_type' => Product::class,
            'commentable_id' => $product->id,
            'user_id' => null,
            'author_name' => 'Old Name',
            'author_email' => 'old@example.test',
            'locale' => 'en',
            'body' => 'Old comment body.',
            'rating' => 4,
            'status' => Comment::STATUS_PENDING,
            'is_featured' => false,
        ]);

        Livewire::actingAs($user)
            ->test(CommentManager::class)
            ->call('edit', $comment->id)
            ->set('editForm.author_name', 'Ana Demo')
            ->set('editForm.author_email', 'ana.demo@example.test')
            ->set('editForm.locale', 'hr')
            ->set('editForm.rating', '5')
            ->set('editForm.status', Comment::STATUS_APPROVED)
            ->set('editForm.is_featured', true)
            ->set('editForm.body', 'Ažurirani demo komentar za prikaz u administraciji.')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('editingId', null);

        $comment->refresh();

        $this->assertSame('Ana Demo', $comment->author_name);
        $this->assertSame('ana.demo@example.test', $comment->author_email);
        $this->assertSame('hr', $comment->locale);
        $this->assertSame(5, $comment->rating);
        $this->assertSame(Comment::STATUS_APPROVED, $comment->status);
        $this->assertTrue($comment->is_featured);
        $this->assertSame('Ažurirani demo komentar za prikaz u administraciji.', $comment->body);
        $this->assertSame($user->id, $comment->reviewed_by);
        $this->assertNotNull($comment->reviewed_at);
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function createProduct(User $user): Product
    {
        $product = Product::query()->create([
            'code' => 'comment-product',
            'sku' => 'COMMENT-1',
            'is_active' => true,
            'base_price' => 20,
            'stock_qty' => 4,
            'payload' => null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $product->translations()->create([
            'locale' => 'en',
            'name' => 'Comment Product',
            'slug' => 'comment-product',
        ]);

        return $product;
    }
}
