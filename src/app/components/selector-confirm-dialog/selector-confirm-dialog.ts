import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MAT_DIALOG_DATA, MatDialogRef, MatDialogModule } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { I18nService } from '../../i18n.service';
import { ExtractionStrategy } from '../../models';

export interface SelectorConfirmData {
    selector: string;
}

export type SelectorConfirmResult = ExtractionStrategy | null;

@Component({
    selector: 'app-selector-confirm-dialog',
    changeDetection: ChangeDetectionStrategy.OnPush,
    imports: [MatDialogModule, MatButtonModule, MatIconModule],
    template: `
        <h2 mat-dialog-title>{{ i18n.strings().selectorConfirmTitle }}</h2>
        <mat-dialog-content>
            <code class="selector-preview">{{ data.selector }}</code>
            <div class="confirm-options">
                <button mat-stroked-button class="confirm-option" (click)="choose('auto')">
                    <mat-icon>auto_fix_high</mat-icon>
                    <div class="confirm-option-text">
                        <span class="confirm-option-label">{{
                            i18n.strings().selectorConfirmFallback
                        }}</span>
                        <span class="confirm-option-hint">{{
                            i18n.strings().selectorConfirmFallbackHint
                        }}</span>
                    </div>
                </button>
                <button mat-stroked-button class="confirm-option" (click)="choose('selector')">
                    <mat-icon>css</mat-icon>
                    <div class="confirm-option-text">
                        <span class="confirm-option-label">{{
                            i18n.strings().selectorConfirmOnly
                        }}</span>
                        <span class="confirm-option-hint">{{
                            i18n.strings().selectorConfirmOnlyHint
                        }}</span>
                    </div>
                </button>
            </div>
        </mat-dialog-content>
        <mat-dialog-actions align="end">
            <button mat-button (click)="skip()">{{ i18n.strings().cancel }}</button>
        </mat-dialog-actions>
    `,
    styles: `
        .selector-preview {
            display: block;
            padding: 8px 12px;
            border-radius: var(--app-radius-sm, 6px);
            background: var(--mat-sys-surface-container);
            font-size: 0.85rem;
            word-break: break-all;
            margin-bottom: 16px;
        }

        .confirm-options {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .confirm-option {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 16px;
            text-align: left;
            height: auto;
            white-space: normal;
        }

        .confirm-option-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .confirm-option-label {
            font-weight: 500;
        }

        .confirm-option-hint {
            font-size: 0.8rem;
            opacity: 0.7;
        }
    `,
})
export class SelectorConfirmDialog {
    private readonly dialogRef = inject(MatDialogRef<SelectorConfirmDialog>);
    readonly data = inject<SelectorConfirmData>(MAT_DIALOG_DATA);
    protected readonly i18n = inject(I18nService);

    choose(strategy: ExtractionStrategy) {
        this.dialogRef.close(strategy);
    }

    skip() {
        this.dialogRef.close(null);
    }
}
