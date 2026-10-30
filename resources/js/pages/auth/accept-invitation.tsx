import { Form, Head } from '@inertiajs/react';
import InvitationController from '@/actions/App/Http/Controllers/Tenant/InvitationController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useT } from '@/lib/i18n';

type Props = { token: string; valid: boolean; name: string | null; email: string | null; passwordRules: string };

export default function AcceptInvitation({ token, valid, name, email, passwordRules }: Props) {
    const t = useT();

    if (!valid) {
        return (
            <>
                <Head title={t('Invitation')} />
                <p className="text-center text-sm text-muted-foreground">{t('This invitation link is invalid or has expired. Ask your school office for a new one.')}</p>
            </>
        );
    }

    return (
        <>
            <Head title={t('Set your password')} />
            <p className="mb-6 text-center text-sm text-muted-foreground">{t('Welcome, :name', { name: name ?? '' })} · {email}</p>
            <Form {...InvitationController.store.form(token)} resetOnSuccess={['password', 'password_confirmation']} className="grid gap-5">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="password">{t('Password')}</Label>
                            <PasswordInput id="password" name="password" autoComplete="new-password" passwordrules={passwordRules} required autoFocus />
                            <InputError message={errors.password} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">{t('Confirm password')}</Label>
                            <PasswordInput id="password_confirmation" name="password_confirmation" autoComplete="new-password" required />
                        </div>
                        <Button type="submit" disabled={processing} className="w-full">{processing && <Spinner />}{t('Set password and sign in')}</Button>
                    </>
                )}
            </Form>
        </>
    );
}

AcceptInvitation.layout = { title: 'Set your password', description: '' };
