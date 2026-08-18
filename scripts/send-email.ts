import nodemailer from "nodemailer";
import { styleText } from 'node:util';

// Load .env file and make process.env available
try {
  process.loadEnvFile();
} catch (error: any) {
  if (error?.code !== 'ENOENT') {
    // Re-throw unexpected errors (e.g., permission issues)
    throw error;
  }
  // Optional: log a warning when running in local dev
  console.warn(styleText(['redBright', 'bold'],'Warning: No .env file found. No email will be sent.'));
}

export async function sendAlertEmail(diffs) {
  // Configure SMTP transporter
  const transporter = nodemailer.createTransport({
    host: process.env.SMTP_HOST || "smtp.gmail.com",
    port: parseInt(process.env.SMTP_PORT || "587"),
    secure: (process.env.SECURE ?? 'true') === 'true', // true for 465, false for other ports
    auth: {
      user: process.env.SMTP_USER, // e.g., your email
      pass: process.env.SMTP_PASS, // e.g., Gmail App Password
    },
  });

  const emailBody = `
🚨 GraphQL Schema Drift Detected

The following changes were detected in the IMDb API response shapes:

${diffs.map((d) => `• ${d}`).join("\n")}

Timestamp: ${new Date().toISOString()}
  `;

  await transporter.sendMail({
    from: `"GraphQL Monitor" <noreply@jcvignoli.com>`,
    to: process.env.ALERT_RECIPIENT_EMAIL,
    subject: "GRAPHQL on Cthulhu: IMDb API Schema Drift Detected",
    text: emailBody,
  });

  console.log("📩 Alert email successfully sent.");
}
