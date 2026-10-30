import type { ComponentProps, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * Form fields that work with Inertia's <Form> (named native inputs) and show
 * server-side validation errors.
 */

type FieldProps = {
    label: string;
    name: string;
    error?: string;
    hint?: string;
    required?: boolean;
    className?: string;
    children: ReactNode;
};

export function Field({ label, name, error, hint, required, className, children }: FieldProps) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <Label htmlFor={name}>
                {label}
                {required && <span className="text-destructive" aria-hidden> *</span>}
            </Label>
            {children}
            {hint && !error && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

type TextFieldProps = Omit<ComponentProps<typeof Input>, 'name'> & {
    label: string;
    name: string;
    error?: string;
    hint?: string;
    fieldClassName?: string;
};

export function TextField({ label, name, error, hint, required, fieldClassName, id, ...props }: TextFieldProps) {
    return (
        <Field label={label} name={id ?? name} error={error} hint={hint} required={required} className={fieldClassName}>
            <Input id={id ?? name} name={name} required={required} aria-invalid={error ? true : undefined} {...props} />
        </Field>
    );
}

export type Option = { value: string | number; label: string };

type SelectFieldProps = Omit<ComponentProps<'select'>, 'name'> & {
    label: string;
    name: string;
    options: Option[];
    placeholder?: string;
    error?: string;
    hint?: string;
    fieldClassName?: string;
};

export const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 aria-invalid:border-destructive dark:bg-input/30';

export function SelectField({ label, name, options, placeholder, error, hint, required, fieldClassName, id, className, ...props }: SelectFieldProps) {
    return (
        <Field label={label} name={id ?? name} error={error} hint={hint} required={required} className={fieldClassName}>
            <select id={id ?? name} name={name} required={required} aria-invalid={error ? true : undefined} className={cn(selectClass, className)} {...props}>
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </Field>
    );
}

type TextareaFieldProps = Omit<ComponentProps<'textarea'>, 'name'> & {
    label: string;
    name: string;
    error?: string;
    fieldClassName?: string;
};

export function TextareaField({ label, name, error, required, fieldClassName, id, className, ...props }: TextareaFieldProps) {
    return (
        <Field label={label} name={id ?? name} error={error} required={required} className={fieldClassName}>
            <textarea
                id={id ?? name}
                name={name}
                required={required}
                rows={3}
                aria-invalid={error ? true : undefined}
                className={cn(
                    'w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive dark:bg-input/30',
                    className,
                )}
                {...props}
            />
        </Field>
    );
}

/** Section card used to group form fields. */
export function FormSection({ title, description, children, className }: { title: string; description?: string; children: ReactNode; className?: string }) {
    return (
        <section className={cn('rounded-xl border border-border/70 bg-card p-5', className)}>
            <header className="mb-4">
                <h2 className="font-medium">{title}</h2>
                {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
            </header>
            {children}
        </section>
    );
}
