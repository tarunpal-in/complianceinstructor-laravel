<?php

declare(strict_types=1);

namespace App\Filament\Resources\Posts\Schemas;

use App\Enums\PublishStatus;
use App\Filament\Forms\Components\SeoFields;
use App\Models\PostCategory;
use App\Models\Tag;
use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Writing an article — CLAUDE.md §5.7.
 *
 * The editor leads and everything administrative follows it, because that is
 * the order somebody writing a post works in. §6 budgets one week for the whole
 * blog module, which is only true if this screen stays a text editor with some
 * fields rather than becoming a second page builder.
 */
class PostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make()->columns(2)->schema([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                        if ($operation === 'create') {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->prefix(config('app.url').'/blog/')
                    // No unique rule: a clash resolves to "{slug}-2" on save and
                    // is reported, rather than refusing the save.
                    ->helperText('Changing this on a published article breaks existing links to it.'),

                Select::make('post_category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    // Not required at save, so a half-written draft can be kept.
                    // It *is* required to publish — Post::problems() says so,
                    // because nothing on the site links to an uncategorised
                    // article.
                    ->helperText('Needed before it can be published.')
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(120),
                        Textarea::make('description')->rows(2)->maxLength(500),
                    ])
                    ->createOptionUsing(fn (array $data): int => PostCategory::create([
                        ...$data,
                        'slug' => Str::slug((string) $data['name']),
                    ])->getKey()),
            ]),

            Section::make('Article')
                ->schema([
                    Textarea::make('excerpt')
                        ->label('Summary')
                        ->rows(2)
                        ->maxLength(500)
                        // This is the text an index card and a search result
                        // show. Left empty it is cut from the body, which reads
                        // like it was cut from the body.
                        ->helperText('Shown on the blog index and quoted by search engines. Leave empty and the opening of the article is used.')
                        ->columnSpanFull(),

                    RichEditor::make('body')
                        ->label('Body')
                        ->required()
                        ->toolbarButtons([
                            'bold', 'italic', 'link', 'bulletList', 'orderedList',
                            'h2', 'h3', 'blockquote', 'codeBlock', 'undo', 'redo',
                        ])
                        ->columnSpanFull(),
                ]),

            Section::make('Presentation')
                ->columns(2)
                ->schema([
                    SpatieMediaLibraryFileUpload::make('cover')
                        ->collection('cover')
                        ->label('Cover image')
                        ->image()
                        ->imageEditor()
                        ->maxSize(4096)
                        ->helperText('Shown on the blog index and at the top of the article. Landscape works best.')
                        ->columnSpanFull(),

                    Select::make('tags')
                        ->label('Tags')
                        ->relationship('tags', 'name')
                        ->multiple()
                        ->preload()
                        ->searchable()
                        /*
                         * The same `tags` table the media library uses, kept
                         * apart by type — one vocabulary for the site, as the
                         * table's own comment intends, without media tags
                         * cluttering this picker.
                         */
                        ->getOptionLabelFromRecordUsing(fn (Tag $record): string => $record->name)
                        ->options(fn (): array => Tag::query()
                            ->where('type', Tag::TYPE_POST)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->createOptionForm([
                            TextInput::make('name')->required()->maxLength(60),
                        ])
                        ->createOptionUsing(fn (array $data): int => Tag::findOrCreateNamed(
                            (string) $data['name'],
                            Tag::TYPE_POST,
                        )->getKey())
                        ->helperText('What the article is about. Shared with the media library, so type a name once.')
                        ->columnSpanFull(),
                ]),

            Section::make('Publishing')
                ->columns(3)
                ->schema([
                    Select::make('status')
                        ->options(PublishStatus::options())
                        ->default(PublishStatus::Draft->value)
                        ->required()
                        ->helperText('Use the Publish action rather than this — it checks the article is finished.'),

                    DateTimePicker::make('published_at')
                        ->label('Publish at')
                        ->seconds(false)
                        ->placeholder('Immediately')
                        ->helperText('A future date holds it back until then.'),

                    Select::make('user_id')
                        ->label('Author')
                        ->options(fn (): array => User::query()
                            ->whereHas('roles')
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->default(fn (): ?int => auth()->id())
                        // Staff only: a byline is a claim about who wrote it,
                        // and a customer account appearing there would be one
                        // nobody made.
                        ->helperText('Shown as the byline.'),

                    Toggle::make('comments_enabled')
                        ->label('Accept comments')
                        ->default(true)
                        // Per article, because closing one thread that has gone
                        // somewhere the client does not want to moderate should
                        // not mean turning the feature off site-wide.
                        ->helperText('Nothing left here is published until it is approved on the Comments screen.')
                        ->columnSpanFull(),
                ]),
            SeoFields::make(),

        ]);
    }
}
